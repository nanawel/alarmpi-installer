<?php

class RoboFile extends \Robo\Tasks
{
    const STORAGE_TYPE_RAWFILE  = 'rawfile';
    const STORAGE_TYPE_DEVICE   = 'device';

    const DEVICE_TYPE_DEVICE    = 'device';
    const DEVICE_TYPE_PARTITION = 'partition';

    const REQUIREMENTS = [
        'bsdtar',
        'dd',
        'losetup',
        'lsblk',
        'mkfs',
        'mount',
        'parted',
        'sh',
        'sudo',
        'sync',
        'wget'
    ];

    const REQUIRED_CONFIG = [
        'alarm_image.url',
        'alarm_image.filename',
        'storage.type',
        'storage.partitions.boot.internal_path',
        'storage.partitions.boot.end',
        'storage.partitions.boot.fs_type',
        'storage.partitions.boot.mountpoint',
        'storage.partitions.root.internal_path',
        'storage.partitions.root.start',
        'storage.partitions.root.fs_type',
        'storage.partitions.root.mountpoint',
    ];

    /** @var string[] */
    protected $loadedDeviceConfigs = [];

    /**
     * @param string $profile
     * @param array $opts
     * @throws \Robo\Exception\AbortTasksException
     * @throws \Robo\Exception\TaskException
     */
    public function build(
        $profile,
        $opts = [
            'overwrite-target' => false,
            'force-download'   => false,
            'force-extract'    => false,
            'skip-cleanup'     => false
        ]
    ) {
        $this->stopOnFail(true);

        $this->yell('Archlinux ARM Installer for Raspberry Pi', null, 'blue');

        $this->_init($profile);

        // Ask sudo password to start sudo session
        $this->_exec('sudo -v');

        switch ($this->config('storage.type')) {
            case self::STORAGE_TYPE_RAWFILE:
                $this->storageImageInit($profile, ['force' => $opts['overwrite-target']]);
                $this->storageImageLoopMount($profile);
                break;

            case self::STORAGE_TYPE_DEVICE:
                $this->storageDeviceInit($profile, ['force' => $opts['overwrite-target']]);
                break;

            default:
                $this->abort("Invalid storage type: {$opts['storage-type']}");
        }
        $this->storageFormat($profile);
        $this->storageMount($profile);

        $this->imageDownload($profile, ['force' => $opts['force-download']]);
        $this->imageExtract($profile, ['force' => $opts['force-extract']]);

        $this->imageExtractPost($profile);

        if (!$opts['skip-cleanup']) {
            $this->say('Cleaning up (unmounting all filesystems)...');
            $this->cleanup($profile, ['unmount-only' => true]);
        }

        $this->yell('Finished!', null, 'green');
    }

    /**
     * @throws \Robo\Exception\AbortTasksException
     */
    public function requirementsCheck() {
        $missing = [];
        foreach (self::REQUIREMENTS as $bin) {
            system("command -v $bin > /dev/null", $rc);
            if ($rc != 0) {
                $missing[] = $bin;
            }
        }
        if ($missing) {
            $this->abort(sprintf('Missing required command(s): %s', implode(', ', $missing)));
        }
        $this->say('<info>Requirements OK!</info>');
    }

    /**
     * @param string|null $profile
     * @throws \Robo\Exception\AbortTasksException
     */
    public function configDump($profile = null) {
        if ($profile) {
            $this->_init($profile);
        }

        $this->say('Current configuration:');
        $this->say(json_encode(\Robo\Robo::config()->export(), JSON_PRETTY_PRINT));
    }

    /**
     * @param string $profile
     * @param array $opts
     * @return \Robo\Result|null
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageImageInit($profile, $opts = ['force' => false]) {
        $this->header('Create image file');
        $this->_init($profile);

        if ($this->config('storage.type') != self::STORAGE_TYPE_RAWFILE) {
            $this->abort("The profile \"$profile\" does not use a raw image as device.");
        }

        $filename = $this->config('storage.image_file.name');

        if (!$opts['force'] && file_exists($filename)) {
            $this->say(sprintf('<comment>Image file "%s" already exists. Skipping init.</comment>', $filename));
            return null;
        }

        if ($loopDevices = $this->_getImageLoopDevices($filename)) {
            $this->say(sprintf(
                '<error>Image file is already mounted on loop device(s): %s</error>',
                implode(', ', $loopDevices)
            ));
            $this->say("<error>Unmount it with \"robo storage:loop-unmount $profile\" before proceeding.</error>");
            $this->abort();
        }

        /** @var \Robo\Collection\CollectionBuilder $collectionBuilder */
        $collectionBuilder = $this->collectionBuilder()->taskExecStack()
            ->exec(sprintf(
                'dd if=/dev/zero of=%s bs=1048576 count=%d',
                $filename,
                $this->config('storage.image_file.size_mb')
            ));
        $this->_appendMakePartitionsTasks($collectionBuilder, $filename, $this->config('storage.partitions'))
            ->rollback($this->taskExec('rm')->args($this->config('storage.image_file.name')));

        $result = $collectionBuilder->run();
        if ($result->wasSuccessful()) {
            $this->say(sprintf('<info>Image file ready at "%s".</info>', $filename));
            if ($this->io()->isVerbose()) {
                exec(sprintf('parted %s print 2>/dev/null', $filename), $output);
                $this->say(implode("\n", $output));
            }
        }

        return $result;
    }

    /**
     * @param string $profile
     * @param array $opts
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageDeviceInit($profile, $opts = ['force' => false]) {
        $this->header('Init device');
        $this->_init($profile);

        if ($this->config('storage.type') != self::STORAGE_TYPE_DEVICE) {
            $this->abort("The profile \"$profile\" does not use a proper device type.");
        }

        $device = $this->config('storage.device');

        if ($mountpoints = $this->_getPartitionOrDeviceMountpoints($device)) {
            $this->say("<error>Device \"$device\" is already mounted at path(s):</error>");
            foreach ($mountpoints as $mp) {
                $this->say("<error>- $mp</error>");
            }
            $this->say("<error>Unmount it with \"robo storage:unmount $profile\" before proceeding.</error>");
            $this->abort();
        }

        if (count($partitionsInfo = $this->_getPartitionOrDeviceInfo($device)) > 1) {
            $this->io()->table(
                array_keys(current($partitionsInfo)),
                $partitionsInfo
            );

            if ($opts['force'] || $this->config('options.no-interaction')) {
                $this->say(sprintf(
                    "<comment>The device \"%s\" does not seem to be empty.</comment>",
                    $device
                ));
            } else {
                $answer = $this->ask(sprintf(
                    "The device \"%s\" does not seem to be empty. Continue anyway? (y/N)",
                    $device
                ));
                if (strtolower($answer) != 'y') {
                    $this->abort();
                }
            }
        }

        /** @var \Robo\Collection\CollectionBuilder $collectionBuilder */
        $collectionBuilder = $this->collectionBuilder()->taskExecStack();
        $this->_appendMakePartitionsTasks($collectionBuilder, $device, $this->config('storage.partitions'));

        $result = $collectionBuilder->run();
        if ($result->wasSuccessful()) {
            $this->say(sprintf('<info>Device ready at "%s".</info>', $device));
            if ($this->io()->isVerbose()) {
                exec(sprintf('parted %s print 2>/dev/null', $device), $output);
                $this->say(implode("\n", $output));
            }
        }

        return $result;
    }

    /**
     * @param string $profile
     * @return \Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageImageLoopMount($profile) {
        $this->header('Mount image file on loop device');
        $this->_init($profile);

        $filename = $this->config('storage.image_file.name');

        if ($loopDevices = $this->_getImageLoopDevices($filename)) {
            $this->say(sprintf(
                '<comment>Image file "%s" is already mounted on: "%s". Skipping.</comment>',
                $filename,
                implode(', ', $loopDevices)
            ));
            $this->say("<comment>Use \"robo storage:image-loop-unmount $profile\" to unmount.</comment>");
            return null;
        }

        $result = $this->taskExec(sprintf(
                'sudo losetup -P --show -f %s',
                $filename
            ))
            ->printOutput(false)        // Somehow necessary when output is used via Result::getMessage() below
            ->run();
        if ($result->wasSuccessful()) {
            $device = $result->getMessage();
            $this->_onContextChange($profile);

            $this->say(sprintf(
                '<info>Image file "%s" successsfully mounted on loop device "%s"</info>',
                $filename,
                $device
            ));
        }

        return $result;
    }

    /**
     * @param string $profile
     * @return \Robo\Result|null
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageImageLoopUnmount($profile) {
        $this->header('Unmount image file from loop device(s)');
        $this->_init($profile);

        $filename = $this->config('storage.image_file.name');

        /** @var \Robo\Collection\CollectionBuilder $collectionBuilder */
        $collectionBuilder = $this->collectionBuilder()->taskExecStack();

        $unmountDevices = [];
        foreach ($this->_getImageLoopDevices($filename) as $loopDevice) {
            if ($mountpoints = $this->_getPartitionOrDeviceMountpoints($loopDevice)) {
                $this->say(sprintf(
                    '<error>Cannot unmount image file %s from loop device %s: mountpoint(s) active:</error>',
                    $filename,
                    $loopDevice
                ));
                foreach ($mountpoints as $mountpoint) {
                    $this->say("<error>  * $mountpoint</error>");
                }
                $this->say("<error>Use \"robo storage:unmount $profile\" to unmount first.</error>");
                $this->abort();
            }

            $collectionBuilder->exec("sudo losetup -d $loopDevice");
            $unmountDevices[] = $loopDevice;
        }

        $result = null;
        if ($unmountDevices) {
            $result = $collectionBuilder->run();
            if ($result->wasSuccessful()) {
                $this->say(sprintf(
                    '<info>Image file %s successsfully unmounted from loop device(s) %s</info>',
                    $filename,
                    implode(', ', $unmountDevices)
                ));
            }
        } else {
            $this->say(sprintf('No loop device to unmount for image file %s.', $filename));
        }

        return $result;
    }

    /**
     * @param string $profile
     * @param array $opts
     * @return Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageFormat($profile, $opts = ['force' => false]) {
        $this->header('Format storage device');

        $this->_init($profile);

        /** @var \Robo\Collection\CollectionBuilder $collectionBuilder */
        $collectionBuilder = $this->collectionBuilder()->taskExecStack();

        $formatted = [];
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            $partitionInfo = current($this->_getPartitionOrDeviceInfo($partitionConfig['path']));
            $format = true;
            if ($partitionInfo['fs_type'] == $partitionConfig['fs_type']) {
                $answer = $this->ask(sprintf(
                    'Partition "%s" already exists with filesystem %s (size = %d MB), format anyway? (y/N)',
                    $partitionName,
                    $partitionInfo['fs_type'],
                    $partitionInfo['size_mb']
                ));
                $format = strtolower($answer) == 'y';
            }

            if ($format) {
                if (!isset($partitionConfig['path']) || empty($partitionConfig['path'])) {
                    $additionalMessage = $this->config('storage.image_file')
                        ? " Use \"robo storage:image-loop-mount $profile\" to mount the image file on a loop device first."
                        : '';
                    $this->abort(sprintf(
                        'Cannot format partition "%s": missing "path" in configuration.%s',
                        $partitionName,
                        $additionalMessage
                    ));
                }

                if ($mountpoints = $this->_getPartitionOrDeviceMountpoints($partitionConfig['path'])) {
                    $this->say(sprintf(
                        '<error>Cannot format partition "%s" at %s: mountpoint(s) active:</error>',
                        $partitionName,
                        $partitionConfig['path']
                    ));
                    foreach ($mountpoints as $mountpoint) {
                        $this->say("<error>  * $mountpoint</error>");
                    }
                    $this->say("<error>Use \"robo storage:unmount $profile\" to unmount first.</error>");
                    $this->abort();
                }

                $collectionBuilder->exec(sprintf(
                    'sudo mkfs.%s %s %s',
                    $partitionConfig['fs_type'],
                    ($opts['force'] ? '-F' : ''),
                    $partitionConfig['path']
                ));
                $formatted[] = $partitionConfig['path'];
            }
        }

        $result = null;
        if ($formatted) {
            $result = $collectionBuilder->run();
            if ($result->wasSuccessful()) {
                $this->say(sprintf(
                    '<info>The partitions of the device %s have been formated successfully.</info>',
                    $this->config('storage.device')
                ));
            }
        }

        return $result;
    }

    /**
     * @param string $profile
     * @return \Robo\Result|null
     * @throws \Robo\Exception\AbortTasksException
     * @throws \Robo\Exception\TaskException
     */
    public function storageMount($profile) {
        $this->header('Mount storage to local directories');

        $this->_init($profile);

        /** @var \Robo\Collection\CollectionBuilder $collectionBuilder */
        $collectionBuilder = $this->collectionBuilder();

        /** @var \Robo\Task\Filesystem\FilesystemStack $fsStack */
        $fsStack = $collectionBuilder->taskFileSystemStack();
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            $fsStack->mkdir($partitionConfig['mountpoint'], 0775);
        }
        /** @var \Robo\Task\Base\ExecStack $execStack */
        $execStack = $collectionBuilder->taskExecStack();
        $mounted = [];
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            if (!isset($partitionConfig['path']) || empty($partitionConfig['path'])) {
                $additionalMessage = $this->config('storage.type') == self::STORAGE_TYPE_RAWFILE
                    ? " Use \"robo storage:image-loop-mount $profile\" to mount the image file on a loop device first."
                    : '';
                $this->abort(sprintf(
                    'Cannot mount partition "%s": missing "path" in configuration.%s',
                    $partitionName,
                    $additionalMessage
                ));
            }

            if (!in_array(
                realpath($partitionConfig['mountpoint']),
                $this->_getPartitionOrDeviceMountpoints($partitionConfig['path'])
            )) {
                $execStack->exec(sprintf(
                    'sudo mount %s %s',
                    $partitionConfig['path'],
                    $partitionConfig['mountpoint']
                ));
                $mounted[$partitionName] = $partitionConfig['mountpoint'];
            }
        }

        $result = null;
        if ($mounted) {
            $result = $collectionBuilder->run();
            if ($result->wasSuccessful()) {
                $this->say(sprintf(
                    '<info>The following partitions of the device %s have been mounted successfully:</info>',
                    $this->config('storage.device')
                ));
                foreach ($mounted as $partition => $mp) {
                    $this->say("<info>$partition: $mp</info>");
                }
            }
        } else {
            $this->say('No partition to mount.');
        }

        return $result;
    }

    /**
     * @param string $profile
     * @return \Robo\Result|null
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageUnmount($profile, $opts = ['ignore-missing' => false]) {
        $this->header('Unmount storage from local directories');

        $this->_init($profile);

        /** @var \Robo\Collection\CollectionBuilder $collectionBuilder */
        $collectionBuilder = $this->collectionBuilder()->taskExecStack();
        $unmounted = [];
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            if (!isset($partitionConfig['path']) || empty($partitionConfig['path'])) {
                if ($opts['ignore-missing']) {
                    continue;
                }

                $additionalMessage = $this->config('storage.type') == self::STORAGE_TYPE_RAWFILE
                    ? " Use \"robo storage:image-loop-mount $profile\" to mount the image file on a loop device first."
                    : '';
                $this->abort(sprintf(
                    'Cannot unmount partition "%s": missing "path" in configuration.%s',
                    $partitionName,
                    $additionalMessage
                ));
            }

            if ($this->_getPartitionOrDeviceMountpoints($partitionConfig['path'])) {
                $collectionBuilder->exec(sprintf('sudo umount %s', $partitionConfig['path']));
                $unmounted[] = $partitionConfig['path'];
            }
        }

        $result = null;
        if ($unmounted) {
            $result = $collectionBuilder->run();
            if ($result->wasSuccessful()) {
                $this->say(sprintf(
                    '<info>The partitions of the device %s have been unmounted successfully.</info>',
                    $this->config('storage.device')
                ));
            }
        } else {
            $this->say(sprintf(
                'No partition to unmount for device %s.',
                $this->config('storage.device') ?: '<empty>'
            ));
        }

        return $result;
    }

    /**
     * @param string $profile
     * @param array $opts
     * @return $this
     * @throws \Robo\Exception\AbortTasksException
     */
    public function imageDownload($profile, $opts = ['force' => false]) {
        $this->header('Download Archlinux ARM image file');

        $this->_init($profile);

        $filename = $this->config('alarm_image.filename');
        if ($opts['force'] || !file_exists($filename)) {
            $this->taskExec('wget')
                ->args([
                    $this->config('alarm_image.url'),
                    '-O',
                    $filename
                ])
                ->run();
        }
        else {
            $this->say(sprintf('<comment>File %s already exists. Skipping download.</comment>', $filename));
        }

        return $this;
    }

    /**
     * @param string $profile
     * @param array $opts
     * @return \Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function imageExtract($profile, $opts = ['force' => false]) {
        $this->header('Extract Archlinux ARM image file');

        $this->_init($profile);

        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            $finder = \Symfony\Component\Finder\Finder::create();
            if ($finder->in($partitionConfig['mountpoint'])->exclude('lost+found')->hasResults()
            ) {
                if ($opts['force'] || $this->config('options.no-interaction')) {
                    $this->say(sprintf(
                        "<comment>The partition \"%s\" (mounted at %s) does not seem to be empty.</comment>",
                        $partitionName,
                        $partitionConfig['mountpoint']
                    ));
                } else {
                    $answer = $this->ask(sprintf(
                        "The partition \"%s\" (mounted at %s) does not seem to be empty. Continue anyway? (y/N)",
                        $partitionName,
                        $partitionConfig['mountpoint']
                    ));
                    if (strtolower($answer) != 'y') {
                        $this->abort();
                    }
                }
            }
        }

        /** @var \Robo\Collection\CollectionBuilder $collectionBuilder */
        $collectionBuilder = $this->collectionBuilder()->taskExecStack()
            ->exec(sprintf(
                "sudo sh -c 'bsdtar -xpf %s -C %s'",
                $this->config('alarm_image.filename'),
                $this->config('storage.partitions.root.mountpoint')
            ));

        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            if ($partitionConfig['internal_path'] != '/') {
                $collectionBuilder->exec(sprintf(
                    'sudo mv %s%s/* %s',    // FIXME Does not handle hidden files
                    $this->config('storage.partitions.root.mountpoint'),
                    $partitionConfig['internal_path'],
                    $this->config("storage.partitions.{$partitionName}.mountpoint")
                ));
            }
        }
        $collectionBuilder->exec('sync');

        $this->say("Extracting archive, this may take some time...");
        $result = $collectionBuilder->run();
        if ($result->wasSuccessful()) {
            $this->say(sprintf(
                '<info>Archlinux ARM system image copied successfully.</info>'
            ));
        }

        return $result;
    }

    /**
     * @param string $profile
     * @throws \Robo\Exception\AbortTasksException
     */
    public function imageExtractPost($profile) {
        $this->_init($profile);

        if ($this->config('storage.type') == self::STORAGE_TYPE_RAWFILE) {
            $rootMountpoints = $this->_getPartitionOrDeviceMountpoints($this->config('storage.partitions.root.path'));
            if (!$rootMountpoints) {
                $this->abort("Root partition of the device is not mounted. Use \"robo storage:mount $profile\" first");
            }
            $rootMountpoint = current($rootMountpoints);

            // Fix /etc/fstab content
            $fstabContent = [
                '# File generated by AlarmPi-Installer'
            ];
            $fstabLineTemplate = "%s\t%s\t%s\t%s\t0\t0";
            $partitionIdx = 1;
            foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
                if (!isset($partitionConfig['internal_path'], $partitionConfig['fs_type'])) {
                    $this->abort("Invalid configuration for partition '$partitionName'.");
                }

                $fstabContent[] = sprintf(
                    $fstabLineTemplate,
                    sprintf('%sp%d', $this->config('storage.internal_device'), $partitionIdx),
                    $partitionConfig['internal_path'],
                    $partitionConfig['fs_type'],
                    $partitionConfig['options'] ?? self::getDefaultFsTabOptions($partitionConfig['fs_type'])
                );
                $partitionIdx++;
            }
            if ($fstabContent) {
                $this->_exec(sprintf(
                    'sudo cp %s/etc/fstab %s/etc/fstab.bak-%d',
                    $rootMountpoint,
                    $rootMountpoint,
                    date('U')
                ));
                $tmpFile = '/tmp/fstab.tmp-' . date('U');
                $this->taskWriteToFile($tmpFile)
                    ->lines($fstabContent)
                    ->run();
                $this->_exec(sprintf(
                    'sudo cp -f %s %s/etc/fstab',
                    $tmpFile,
                    $rootMountpoint
                ));
                $this->taskFilesystemStack()->remove($tmpFile)->run();

                $this->say('<info>File /etc/fstab has been successfully modified.</info>');
            }
        }
    }

    /**
     * @param string $profile
     * @param array $opts
     * @return \Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function cleanup(
        $profile,
        $opts = [
            'unmount-only' => false,
            'force'        => false
        ]
    ) {
        $this->_init($profile);
        if ($this->config('storage.device')) {
            $this->storageUnmount($profile, ['ignore-missing' => true]);
        }
        if ($this->config('storage.type') == self::STORAGE_TYPE_RAWFILE) {
            $this->storageImageLoopUnmount($profile);
        }

        $this->say('<info>Storage unmounted successfully.</info>');

        if (!$opts['unmount-only']) {
            if (is_file($alarmImage = $this->config('alarm_image.filename'))) {
                if ($opts['force']
                    || strtolower($this->ask(sprintf('Delete Archlinux ARM image archive "%s"? (y/N)', $alarmImage))) == 'y'
                ) {
                    $this->taskExec('rm')->args($this->config('alarm_image.filename'))->run();
                    $this->say(sprintf('%s has been deleted.', $this->config('alarm_image.filename')));
                } else {
                    $this->say(sprintf('%s left on disk.', $this->config('alarm_image.filename')));
                }
            }

            if ($this->config('storage.type') == self::STORAGE_TYPE_RAWFILE
                && is_file($rawImage = $this->config('storage.image_file.name'))
            ) {
                if ($opts['force']
                    || strtolower($this->ask(sprintf('Delete raw image file "%s"? (y/N)', $rawImage))) == 'y'
                ) {
                    $this->taskExec('rm')->args($this->config('storage.image_file.name'))->run();
                    $this->say(sprintf('%s has been deleted.', $this->config('storage.image_file.name')));
                } else {
                    $this->say(sprintf('%s left on disk.', $this->config('storage.image_file.name')));
                }
            }
        }

        $this->say('<info>Cleanup completed successfully.</info>');
    }

    // ========================================================================
    //        UTILITIES (not tasks)
    // ========================================================================

    /**
     * @param \Robo\Collection\CollectionBuilder $collectionBuilder
     * @param string $device
     * @param array $partitionsConfig
     */
    protected function _appendMakePartitionsTasks(
        \Robo\Collection\CollectionBuilder $collectionBuilder,
        $device,
        array $partitionsConfig
    ) {
        // Partition table
        $collectionBuilder->exec(sprintf(
            'sudo parted --script %s mklabel msdos',
            $device
        ));

        // Partitions
        foreach ($partitionsConfig as $partitionName => $partitionConfig) {
            $collectionBuilder->exec(sprintf(
                'sudo parted -a optimal --script %s mkpart %s %s %s %s',
                $device,
                $partitionConfig['type'] ?? 'primary',
                self::getPartitionTableFsType($partitionConfig['fs_type']),
                $partitionName == 'boot' ? '2048s' : $partitionConfig['start'],
                $partitionConfig['end'] ?? '100%'
            ));
        }

        return $collectionBuilder;
    }

    /**
     * @param string $imageFile
     * @return string[]
     */
    protected function _getImageLoopDevices($imageFile) {
        exec('losetup -a', $output);

        $devices = [];
        foreach ($output as $line) {
            list($device) = explode(':', $line);
            if (strpos($line, realpath($imageFile)) !== false) {
                $devices[] = $device;
            }
        }
        sort($devices);

        return $devices;
    }

    /**
     * @param string $profile
     * @throws \Robo\Exception\AbortTasksException
     */
    protected function _populateConfigLoopDevicePath($profile) {
        $this->_init($profile);

        if ($this->config('storage.type') != self::STORAGE_TYPE_RAWFILE) {
            $this->say("Profile \"$profile\" does not use a loop device.");

            return;
        }

        if ($loopDevices = $this->_getImageLoopDevices($this->config('storage.image_file.name'))) {
            $loopDevice = current($loopDevices);    // Take the first match
            $this->setConfig('storage.device', $loopDevice);
        }
    }

    /**
     * @param string $profile
     * @throws \Robo\Exception\AbortTasksException
     */
    protected function _populateDefaultConfig($profile) {
        $this->_init($profile);

        if ($device = $this->config('storage.device')) {
            $partitionIdx = 1;
            foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
                $this->setConfig("storage.partitions.$partitionName.path", "{$device}p{$partitionIdx}");
                $partitionIdx++;
            }
        }
        if (!$this->config('storage.internal_device')) {
            $this->setConfig('storage.internal_device', '/dev/mmcblk0');
        }
    }

    /**
     * @param string $imageFile
     * @return string[]
     */
    protected function _getPartitionOrDeviceMountpoints($partitionOrDevice) {
        $mtab = explode("\n", file_get_contents('/etc/mtab'));
        $type = self::getDeviceType($partitionOrDevice);

        $mountpoints = [];
        foreach (array_filter($mtab) as $line) {
            list($p, $mountpoint) = explode(' ', $line);
            if ($type === self::DEVICE_TYPE_PARTITION && $p === $partitionOrDevice) {
                $mountpoints[] = $mountpoint;
            } elseif ($type === self::DEVICE_TYPE_DEVICE && strpos("{$p}p", $partitionOrDevice) === 0) {
                $mountpoints[] = $mountpoint;
            }
        }

        return $mountpoints;
    }

    /**
     * @param string $partitionOrDevice
     * @return array
     */
    protected function _getPartitionOrDeviceInfo($partitionOrDevice) {
        exec("lsblk $partitionOrDevice -o PATH,FSTYPE,SIZE -b -n 2>/dev/null", $output, $rc);

        $info = [];
        if ($rc != 0) {
            $info[$partitionOrDevice] = [
                'device'  => $partitionOrDevice,
                'fs_type' => null,
                'size'    => null,
                'size_mb' => null
            ];
        } else {
            foreach (array_filter($output) as $line) {
                $data = preg_split('#\s+#', $line);
                if (count($data) == 2) {    // Not yet formatted
                    list($dev, $size) = $data;
                    $fstype = null;
                } else {
                    list($dev, $fstype, $size) = $data;
                }
                $info[$dev] = [
                    'device'  => $dev,
                    'fs_type' => $fstype,
                    'size'    => $size,
                    'size_mb' => $size / 1024 / 1024
                ];
            }
        }

        return $info;
    }

    /**
     * @param string $profile
     * @param bool $forceLoadProfile
     * @throws \Robo\Exception\AbortTasksException
     */
    protected function _init($profile, $forceLoadProfile = false) {
        static $requirementsChecked = false;
        if (!$requirementsChecked) {
            $this->requirementsCheck();
            $requirementsChecked = true;
        }

        $files = [];

        $defaultConfigFile = __DIR__ . "/profiles/$profile.yml";
        if (is_file($defaultConfigFile)) {
            $files[] = $defaultConfigFile;
        }

        $localConfigFile = __DIR__ . "/profiles/$profile.override.yml";
        if (is_file($localConfigFile)) {
            $files[] = $localConfigFile;
        }

        if (!$files) {
            throw new InvalidArgumentException("Invalid or unsupported profile: $profile.");
        }

        if ($forceLoadProfile || !($this->loadedDeviceConfigs[$profile] ?? false)) {
            \Robo\Robo::loadConfiguration($files);
            $this->loadedDeviceConfigs[$profile] = true;
            $this->_onContextChange($profile);
            $this->say("<info>Configuration loaded for profile: $profile</info>");

            $missingConfig = [];
            foreach (self::REQUIRED_CONFIG as $path) {
                if (empty($this->config($path))) {
                    $missingConfig[] = $path;
                }
            }
            if ($missingConfig) {
                $this->say("<error>Missing required configurations for profile \"$profile\":</error>");
                foreach ($missingConfig as $path) {
                    $this->say("<error>- $path</error>");
                }
                $this->abort();
            }
        }
    }

    /**
     * @param string $profile
     * @throws \Robo\Exception\AbortTasksException
     */
    protected function _onContextChange($profile) {
        $this->_populateConfigLoopDevicePath($profile);
        $this->_populateDefaultConfig($profile);
    }

    /**
     * @param string $title
     */
    protected function header($title) {
        $this->say(sprintf("=== $title ==="));
    }

    /**
     * @param string|null $message
     * @throws \Robo\Exception\AbortTasksException
     */
    protected function abort($message = null) {
        throw new \Robo\Exception\AbortTasksException($message ?? 'Aborted.');
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    protected function config($key, $default = null) {
        return \Robo\Robo::Config()->has($key)
            ? \Robo\Robo::Config()->get($key)
            : $default;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    protected function setConfig($key, $value, $context = 'default') {
        if ($context == 'default') {
            \Robo\Robo::Config()->setDefault($key, $value);
        } else {
            \Robo\Robo::Config()->set($key, $value);
        }
    }

    /**
     * @param string $device
     * @return string
     */
    protected static function getDeviceType($device) {
        switch (1) {
            case preg_match('#^/dev/\w+\d+$#', $device):
                return self::DEVICE_TYPE_DEVICE;
            case preg_match('#^/dev/\w+\d+p\d+$#', $device):
                return self::DEVICE_TYPE_PARTITION;
            default:
                throw new InvalidArgumentException("Invalid device identifier: $device");
        }
    }

    /**
     * @param string $fsType
     * @return string
     */
    protected static function getPartitionTableFsType($fsType) {
        $mapping = [
            'vfat' => 'fat32'
        ];

        return $mapping[$fsType] ?? $fsType;
    }

    /**
     * @param string $fsType
     * @return string
     */
    protected static function getDefaultFsTabOptions($fsType) {
        $mapping = [
            'ext2' => 'defaults,noatime,nodiratime',
            'ext3' => 'defaults,noatime,nodiratime',
            'ext4' => 'defaults,noatime,nodiratime',
        ];

        return $mapping[$fsType] ?? 'defaults';
    }
}

<?php

class RoboFile extends \Robo\Tasks
{
    const STORAGE_TYPE_RAWFILE  = 'rawfile';
    const STORAGE_TYPE_DEVICE   = 'device';

    const DEVICE_TYPE_DEVICE    = 'device';
    const DEVICE_TYPE_PARTITION = 'partition';

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

        if (!$opts['skip-cleanup']) {
            $this->say('Cleaning up (unmounting all filesystems)...');
            // TODO
            $this->storageUnmount($profile);
            if ($this->config('storage.type') == self::STORAGE_TYPE_RAWFILE) {
                $this->storageImageLoopUnmount($profile);
            }
            $this->say('Cleaning up finished successfully.');
        }

        $this->yell('Finished!', null, 'green');
    }

    /**
     * @throws \Robo\Exception\AbortTasksException
     */
    public function requirementsCheck() {
        $mandatory = [
            'bsdtar',
            'dd',
            'losetup',
            'mkfs',
            'mount',
            'parted',
            'sudo',
            'sync',
            'wget'
        ];
        $optional = [];

        $missing = [];
        foreach ($mandatory as $bin) {
            system("command -v $bin > /dev/null", $rc);
            if ($rc != 0) {
                $missing[] = $bin;
            }
        }
        if ($missing) {
            $this->abort(sprintf('Missing required command(s): %s', implode(', ', $missing)));
        }
        foreach ($optional as $bin) {
            system("command -v $bin > /dev/null", $rc);
            if ($rc != 0) {
                $this->say("<comment>Missing optional command '$bin'</comment>");
            }
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

        $filename = $this->config('storage.image_file.name');

        if (!$opts['force'] && file_exists($filename)) {
            $this->say(sprintf('<comment>Image file %s already exists. Skipping init.</comment>', $filename));
            return null;
        }

        if ($devices = $this->_getImageLoopDevices($filename)) {
            $this->say(sprintf(
                '<error>Image file is already mounted on loop device(s): %s</error>',
                implode(', ', $devices)
            ));
            $this->say('<error>Unmount it with "robo storage:loop-unmount" before proceeding.</error>');
            $this->abort();
        }

        $collection = $this->collectionBuilder();

        /** @var Robo\Result $result */
        $result = $collection->taskExecStack()
            ->exec(sprintf(
                'dd if=/dev/zero of=%s bs=1048576 count=%d',
                $filename,
                $this->config('storage.image_file.size_mb')
            ))
            ->exec(sprintf(
                'parted --script %s mklabel msdos',
                $filename
            ))
            // FIXME Use configurable partition definitions
            ->exec(sprintf(
                'parted --script %s mkpart primary %s 2048s %s',
                $filename,
                $this->config('storage.partitions.boot.type'),
                $this->config('storage.partitions.boot.size')
            ))
            ->exec(sprintf(
                'parted --script %s mkpart primary %s %s %s',
                $filename,
                $this->config('storage.partitions.root.type'),
                $this->config('storage.partitions.boot.size'),
                $this->config('storage.partitions.root.size')
            ))
            ->rollback($this->taskExec('rm')->args($this->config('storage.image_file.name')))
            ->run();

        if ($result->wasSuccessful()) {
            $this->say(sprintf('<info>Image file ready at %s.</info>', $filename));
            if ($this->io()->isVerbose()) {
                exec(sprintf('parted %s print 2>/dev/null', $filename), $output);
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
                '<comment>Image file %s is already mounted on: %s. Skipping.</comment>',
                $filename,
                implode(', ', $loopDevices)
            ));
            $this->say('<comment>Use "robo storage:image-loop-unmount" to unmount.</comment>');
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
            $this->_populateConfigLoopDevicePath($profile);

            $this->say(sprintf(
                '<info>Image file %s successsfully mounted on loop device %s</info>',
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

        /** @var \Robo\Task\Base\ExecStack $collection */
        $collection = $this->collectionBuilder()->taskExecStack();

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
                $this->say('<error>Use "robo storage:unmount" to unmount first.</error>');
                $this->abort();
            }

            $collection->exec("sudo losetup -d $loopDevice");
            $unmountDevices[] = $loopDevice;
        }

        $result = null;
        if ($unmountDevices) {
            $result = $collection->run();
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
     * @return Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageFormat($profile) {
        $this->header('Format storage device');

        $this->_init($profile);

        /** @var \Robo\Task\Base\ExecStack $collection */
        $collection = $this->collectionBuilder()->taskExecStack();

        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            if (!isset($partitionConfig['path']) || empty($partitionConfig['path'])) {
                $additionalMessage = $this->config('storage.image_file')
                    ? ' Use "robo storage:image-loop-mount" to mount the image file on a loop device first.'
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
                $this->say('<error>Use "robo storage:unmount" to unmount first.</error>');
                $this->abort();
            }

            $collection->exec(sprintf(
                'sudo mkfs.%s %s %s',
                self::getMkfsFormat($partitionConfig['type']),
                ($this->config('options.no-interaction') ? '-F' : ''),
                $partitionConfig['path']
            ));
        }

        $result = $collection->run();
        if ($result->wasSuccessful()) {
            $this->say(sprintf(
                '<info>The partitions of the device %s have been formated successfully.</info>',
                $this->config('storage.device')
            ));
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

        /** @var \Robo\Collection\CollectionBuilder $collection */
        $collection = $this->collectionBuilder();

        /** @var \Robo\Task\Filesystem\FilesystemStack $fsStack */
        $fsStack = $collection->taskFileSystemStack();
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            $fsStack->mkdir($partitionConfig['mountpoint'], 0775);
        }
        /** @var \Robo\Task\Base\ExecStack $execStack */
        $execStack = $collection->taskExecStack();
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            if (!isset($partitionConfig['path']) || empty($partitionConfig['path'])) {
                $additionalMessage = $this->config('storage.type') == self::STORAGE_TYPE_RAWFILE
                    ? ' Use "robo storage:image-loop-mount" to mount the image file on a loop device first.'
                    : '';
                $this->abort(sprintf(
                    'Cannot mount partition "%s": missing "path" in configuration.%s',
                    $partitionName,
                    $additionalMessage
                ));
            }

            if (!in_array(
                $partitionConfig['mountpoint'],
                $this->_getPartitionOrDeviceMountpoints($partitionConfig['path'])
            )) {
                $execStack->exec(sprintf(
                    'sudo mount %s %s',
                    $partitionConfig['path'],
                    $partitionConfig['mountpoint']
                ));
            }
        }

        $result = $collection->run();
        if ($result->wasSuccessful()) {
            $this->say(sprintf(
                '<info>The partitions of the device %s have been mounted successfully.</info>',
                $this->config('storage.device')
            ));
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

        /** @var \Robo\Collection\CollectionBuilder $collection */
        $collection = $this->collectionBuilder();

        /** @var \Robo\Task\Base\ExecStack $execStack */
        $execStack = $collection->taskExecStack();
        $unmounted = [];
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            if (!isset($partitionConfig['path']) || empty($partitionConfig['path'])) {
                if ($opts['ignore-missing']) {
                    continue;
                }

                $additionalMessage = $this->config('storage.type') == self::STORAGE_TYPE_RAWFILE
                    ? ' Use "robo storage:image-loop-mount" to mount the image file on a loop device first.'
                    : '';
                $this->abort(sprintf(
                    'Cannot unmount partition "%s": missing "path" in configuration.%s',
                    $partitionName,
                    $additionalMessage
                ));
            }

            if ($this->_getPartitionOrDeviceMountpoints($partitionConfig['path'])) {
                $execStack->exec(sprintf('sudo umount %s', $partitionConfig['path']));
                $unmounted[] = $partitionConfig['path'];
            }
        }

        $result = null;
        if ($unmounted) {
            $result = $collection->run();
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
     */
    public function storageDeviceInit($profile, $opts = ['force' => false]) {
        $this->header('Init device');

        $this->yell('NOT IMPLEMENTED: ' . __FUNCTION__, null, 'red');
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
                        "<comment>The partition '%s' (mounted at %s) does not seem to be empty.</comment>",
                        $partitionName,
                        $partitionConfig['mountpoint']
                    ));
                } else {
                    $answer = $this->askDefault(sprintf(
                        "The partition '%s' (mounted at %s) does not seem to be empty. Continue anyway? (y/n)",
                        $partitionName,
                        $partitionConfig['mountpoint']
                    ), 'y');
                    if (strtolower($answer) != 'y') {
                        $this->abort();
                    }
                }
            }
        }

        /** @var \Robo\Collection\CollectionBuilder $collection */
        $collection = $this->collectionBuilder();

        /** @var \Robo\Task\Base\ExecStack $execStack */
        $collection->taskExecStack()
            ->exec(sprintf(
                'bsdtar -xpf %s -C %s',
                $this->config('alarm_image.filename'),
                $this->config('storage.partitions.root.mountpoint')
            ))
            ->exec(sprintf(
                'mv %s/boot/* %s',
                $this->config('storage.partitions.root.mountpoint'),
                $this->config('storage.partitions.boot.mountpoint')
            ))
            ->exec('sync');

        $result = $collection->run();
        if ($result->wasSuccessful()) {
            $this->say(sprintf(
                '<info>Archlinux ARM system image copied successfully.</info>'
            ));
        }

        return $result;
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
            $this->say(sprintf("Profile %s does not use a loop device.", $profile));

            return;
        }

        if ($loopDevices = $this->_getImageLoopDevices($this->config('storage.image_file.name'))) {
            $loopDevice = current($loopDevices);    // Take the first match
            $this->setConfig('storage.device', $loopDevice);

            $partitionIdx = 1;
            foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
                $this->setConfig("storage.partitions.$partitionName.path", "{$loopDevice}p{$partitionIdx}");
                $partitionIdx++;
            }
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
            $this->_onTargetConfigLoaded($profile);
            $this->say("<info>Configuration loaded for profile: $profile</info>");
        }
    }

    /**
     * @param string $profile
     */
    protected function _onTargetConfigLoaded($profile) {
        $this->_populateConfigLoopDevicePath($profile);
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
     * @param string $partitionType
     * @return string
     */
    protected static function getMkfsFormat($partitionType) {
        $mapping = [
            'fat32' => 'vfat'
        ];

        return $mapping[$partitionType] ?? $partitionType;
    }
}

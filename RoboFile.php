<?php

class RoboFile extends \Robo\Tasks
{
    const DEVICE_TYPE_DEVICE    = 'device';
    const DEVICE_TYPE_PARTITION = 'partition';

    /** @var string[] */
    protected $loadedDeviceConfigs = [];

    /**
     * @param string $profile
     * @param array $opts
     * @throws \Robo\Exception\AbortTasksException
     */
    public function build(
        $profile,
        $opts = [
            'overwrite-target' => false,
            'force-download'   => false,
            'force-extract'    => false
        ]
    ) {
        $this->stopOnFail(true);

        $this->yell('Archlinux ARM Installer for Raspberry Pi', null, 'blue');

        $this->requirementsCheck();
        $this->loadTargetConfig($profile);

        switch ($this->config('storage.type')) {
            case 'rawfile':
                $this->storageImageInit($profile, ['force' => $opts['overwrite-target']]);
                $this->storageImageLoopMount($profile);
                break;

            case 'device':
                $this->storageDeviceInit(['force' => $opts['overwrite-target']]);
                break;

            default:
                throw new \Robo\Exception\AbortTasksException("Invalid storage type: {$opts['storage-type']}");
        }
        $this->storageFormat($profile);
        $this->storageMount($profile);

        $this->imageDownload($profile, ['force' => $opts['force-download']]);
        $this->imageExtract($profile, ['force' => $opts['force-extract']]);
    }

    /**
     * @throws \Robo\Exception\AbortTasksException
     */
    public function requirementsCheck() {
        $mandatory = [
            'wget',
            'dd',
            'bsdtar',
            'parted',
            'losetup',
            'mount',
            'sudo'
        ];
        $optional = [];

        foreach ($mandatory as $bin) {
            system("command -v $bin > /dev/null", $rc);
            if ($rc != 0) {
                throw new \Robo\Exception\AbortTasksException("Missing required command '$bin'");
            }
        }
        foreach ($optional as $bin) {
            system("command -v $bin > /dev/null", $rc);
            if ($rc != 0) {
                $this->say("<comment>Missing optional command '$bin'</comment>");
            }
        }
        $this->say('<info>Requirements OK!</info>');
    }

    public function configDump($profile = null) {
        if ($profile) {
            $this->loadTargetConfig($profile);
        }

        $this->say('Current configuration:');
        $this->say(json_encode(\Robo\Robo::config()->export(), JSON_PRETTY_PRINT));
    }

    /**
     * @param string $profile
     * @param array $opts
     * @return \Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageImageInit($profile, $opts = ['force' => false]) {
        $this->say('== Create image file ==');

        $this->loadTargetConfig($profile);
        $filename = $this->config('storage.image_file.name');

        $collection = $this->collectionBuilder();
        if ($opts['force'] || !file_exists($filename)) {
            $this->checkImageMountedOnLoopDevice($filename);

            $collection->taskExecStack()
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
                ->rollback($this->taskExec('rm')->args($this->config('storage.image_file.name')));
        }
        else {
            $this->say(sprintf('<comment>Image file %s already exists. Skipping init.</comment>', $filename));
        }

        $result = $collection->run();
        if ($result->wasSuccessful()) {
            $this->say(sprintf('<info>Image file ready at %s</info>', $filename));
            exec(sprintf('parted %s print 2>/dev/null', $filename), $output);
            $this->say(implode("\n", $output));
        }

        return $result;
    }

    /**
     * @param string $profile
     * @return \Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageImageLoopMount($profile) {
        $this->say('== Mount image file on loop device ==');

        $this->loadTargetConfig($profile);

        $filename = $this->config('storage.image_file.name');

        $this->checkImageMountedOnLoopDevice($filename);

        $result = $this->taskExec(sprintf(
                'sudo losetup -P --show -f %s',
                $filename
            ))
            ->printOutput(false)        // Somehow necessary when output is used via Result::getMessage() below
            ->run();
        if ($result->wasSuccessful()) {
            $device = $result->getMessage();
            $this->populateConfigLoopDevicePath($profile);

            $this->say(sprintf(
                '<info>Image file %s successsfully mounted on loop device %s</info>',
                $filename,
                $device
            ));
        }

        return $result;
    }

    /**
     * @return \Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageLoopUnmount($filename = null) {
        $this->say('== Unmount image file from loop device ==');

        if ($filename === null) {
            $filename = $this->config('storage.image_file.name');
        }
        if (!$filename) {
            throw new \Robo\Exception\AbortTasksException('Missing image filename.');
        }

        if ($device = $this->getImageLoopDevice($filename)) {
            $result = $this->taskExec("sudo losetup -d $device")->run();
        }
        if ($result->wasSuccessful()) {
            $this->say(sprintf(
                '<info>Image file %s successsfully unmounted from loop device %s</info>',
                $filename,
                $device
            ));
        }

        return $result;
    }

    /**
     * @param string $profile
     * @return Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storageFormat($profile) {
        $this->say('== Format storage device ==');

        $this->loadTargetConfig($profile);

        /** @var \Robo\Task\Base\ExecStack $collection */
        $collection = $this->collectionBuilder()->taskExecStack();

        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            $this->checkPartitionMounted($partitionConfig['path']);

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
     * @throws \Robo\Exception\TaskException
     */
    public function storageMount($profile) {
        $this->say('== Mount storage to local directories ==');

        $this->loadTargetConfig($profile);

        /** @var \Robo\Task\Base\ExecStack $collection */
        $collection = $this->collectionBuilder();

        $fsStack = $collection->taskFileSystemStack();
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            $fsStack->mkdir($partitionConfig['mountpoint'], 0775);
        }
        $execStack = $collection->taskExecStack();
        foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
            if (!in_array(
                $partitionConfig['mountpoint'],
                $this->getPartitionOrDeviceMountpoints($partitionConfig['path'])
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
     * @return \Robo\Result
     * @throws \Robo\Exception\AbortTasksException
     */
    public function storagePartitionUnmount($partition) {
        /** @var \Robo\Task\Base\ExecStack $collection */
        $collection = $this->collectionBuilder()->taskExecStack();

        $umountRequired = false;
        foreach ($this->getPartitionOrDeviceMountpoints($partition) as $mountpoint) {
            $collection->exec(sprintf('sudo umount %s', $mountpoint));
            $umountRequired = true;
        }

        if (!$umountRequired) {
            $this->say(sprintf(
                '<comment>Partition %s is not currently mounted. Nothing to do.</comment>',
                $partition
            ));
            $result = Robo\Result::success($collection);
        } else {
            $result = $collection->run();
            if ($result->wasSuccessful()) {
                $this->say(sprintf(
                    '<info>Partition %s successsfully unmounted.</info>',
                    $partition
                ));
            }
        }

        return $result;
    }

    /**
     * @param string $profile
     * @param array $opts
     */
    public function storageDeviceInit($profile, $opts = ['force' => false]) {
        $this->yell('NOT IMPLEMENTED: ' . __FUNCTION__, null, 'red');
    }

    public function imageDownload($profile, $opts = ['force' => false]) {
        $this->say('== Download Archlinux ARM image file ==');

        $this->loadTargetConfig($profile);

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

    public function imageExtract($profile, $opts = ['force' => false]) {
        $this->say('== Extract Archlinux ARM image file ==');

        $this->loadTargetConfig($profile);

        $this->yell('NOT IMPLEMENTED: ' . __FUNCTION__, null, 'red');
    }

    public function rawimageCreate($opts = ['force' => false]) {

        //$this->taskExec()
    }

    // ========================================================================
    //        UTILITIES (not tasks)
    // ========================================================================

    /**
     * @param string $filename
     * @throws \Robo\Exception\AbortTasksException
     */
    protected function checkImageMountedOnLoopDevice($filename) {
        if ($device = $this->getImageLoopDevice($filename)) {
            $this->say(sprintf(
                '<error>Image file %s is already mounted on a loopback device %s.</error>',
                $filename,
                $device
            ));
            if ($this->config('options.no-interaction')) {
                $answer = 'y';
            } else {
                $answer = $this->askDefault('Unmount before proceeding? (y/n)', 'y');
            }
            if (strtolower($answer) == 'y') {
                $this->storageLoopUnmount($filename);
            } else {
                throw new \Robo\Exception\AbortTasksException(sprintf(
                    'Image file %s is already mounted on a loopback device %s.',
                    $filename,
                    $device
                ));
            }
        }
    }

    /**
     * @param string $imageFile
     * @return string|null
     */
    protected function getImageLoopDevice($imageFile) {
        exec('losetup -a', $output);

        foreach ($output as $line) {
            list($device) = explode(':', $line);
            if (strpos($line, realpath($imageFile)) !== false) {
                return $device;
            }
        }

        return null;
    }

    /**
     * @param string $profile
     */
    protected function populateConfigLoopDevicePath($profile) {
        $this->loadTargetConfig($profile);

        if (!$this->config('storage.image_file.name')) {
            $this->say(sprintf("Profile %s does not use a loop device.", $profile));

            return;
        }

        if ($loopDevice = $this->getImageLoopDevice($this->config('storage.image_file.name'))) {
            $this->setConfig('storage.device', $loopDevice);

            $partitionIdx = 1;
            foreach ($this->config('storage.partitions') as $partitionName => $partitionConfig) {
                $this->setConfig("storage.partitions.$partitionName.path", "{$loopDevice}p{$partitionIdx}");
                $partitionIdx++;
            }
        }
    }

    /**
     * @param string $partition
     * @throws \Robo\Exception\AbortTasksException
     */
    protected function checkPartitionMounted($partition) {
        if (!$partition) {
            throw new InvalidArgumentException('Partition name cannot be empty.');
        }

        if ($mountpoints = $this->getPartitionOrDeviceMountpoints($partition)) {
            $this->say(sprintf(
                '<error>Partition %s is already mounted at %s.</error>',
                $partition,
                explode(', ', $mountpoints)
            ));
            if ($this->config('options.no-interaction')) {
                $answer = 'y';
            } else {
                $answer = $this->askDefault('Unmount before proceeding? (y/n)', 'y');
            }
            if (strtolower($answer) == 'y') {
                $this->storagePartitionUnmount($partition);
            } else {
                throw new \Robo\Exception\AbortTasksException(sprintf(
                    '<error>Partition %s is already mounted at %s.</error>',
                    $partition,
                    explode(', ', $mountpoints)
                ));
            }
        }
    }

    /**
     * @param string $imageFile
     * @return string[]
     */
    protected function getPartitionOrDeviceMountpoints($partitionOrDevice) {
        $mtab = explode("\n", file_get_contents('/etc/mtab'));

        $type = self::getDeviceType($partitionOrDevice);

        $mountpoints = [];
        foreach (array_filter($mtab) as $line) {
            try {
                list($p, $mountpoint) = explode(' ', $line);
                if ($type === self::DEVICE_TYPE_PARTITION && $p === $partitionOrDevice) {
                    $mountpoints[] = $mountpoint;
                } elseif ($type === self::DEVICE_TYPE_DEVICE && strpos("{$p}p", $partitionOrDevice) === 0) {
                    $mountpoints[] = $mountpoint;
                }
            }
            catch (\Throwable $e) {
                continue;
            }
        }

        return $mountpoints;
    }

    /**
     * @param string $profile
     * @param bool $force
     */
    protected function loadTargetConfig($profile, $force = false) {
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

        if ($force || !($this->loadedDeviceConfigs[$profile] ?? false)) {
            \Robo\Robo::loadConfiguration($files);
            $this->loadedDeviceConfigs[$profile] = true;
            $this->onTargetConfigLoaded($profile);
            $this->say("<info>Configuration loaded for profile: $profile</info>");
        }
    }

    /**
     * @param string $profile
     */
    protected function onTargetConfigLoaded($profile) {
        $this->populateConfigLoopDevicePath($profile);
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
            case preg_match('#^/dev/\w+\d+p\d+$#', $device):
                return self::DEVICE_TYPE_DEVICE;
            case preg_match('#^/dev/\w+\d+$#', $device):
                return self::DEVICE_TYPE_PARTITION;
            default:
                throw new InvalidArgumentException('Invalid device identifier: ' . $device);
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

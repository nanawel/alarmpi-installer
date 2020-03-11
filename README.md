Archlinux ARM Pi Installer
==========================

This tool aims to ease the creation of an Archlinux ARM storage media for
Raspberry Pi devices.

In a nutshell it can:
- partition an SD card according to a given configuration ("profile")
- format said partitions
- mount partitions in local directories
- download official ArchlinuxARM Pi images and extract their content to the
  right
  partitions
- update device's `/etc/fstab` after extraction to reflect the partitions
  structure

It also provides an easy way to create raw image files that can then be
used with [QEMU](https://www.qemu.org/).

See [examples](#examples) below and in the [`profiles/`](profiles/) directory.

> **Note:** Only Raspberry Pi 1 & 2 are supported at the moment but new
  profiles can easily be created in the dedicated folder `profiles/`, as
  long as the steps stay similar.

## Requirements

- Linux system
- PHP 7+
- [Robo](https://robo.li/) task runner
  ```
  sudo wget https://robo.li/robo.phar -O /usr/local/bin/robo.phar
  sudo chmod +x /usr/local/bin/robo.phar
  sudo ln -s /usr/local/bin/robo.phar /usr/local/bin/robo
  ```

For the rest, run `robo requirements:check` to validate your environment.

## Usage

### Synopsis

```
$ robo
Robo 1.4.10

Usage:
  command [options] [arguments]

Options:
  -h, --help                           Display this help message
  -q, --quiet                          Do not output any message
  -V, --version                        Display this application version
      --ansi                           Force ANSI output
      --no-ansi                        Disable ANSI output
  -n, --no-interaction                 Do not ask any interactive question
      --simulate                       Run in simulated mode (show what would have happened).
      --progress-delay=PROGRESS-DELAY  Number of seconds before progress bar is displayed in long-running task collections. Default: 2s. [default: 2]
  -D, --define=DEFINE                  Define a configuration item value. (multiple values allowed)
  -v|vv|vvv, --verbose                 Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Available commands:
  build                       Build an image using the provided <profile>
  cleanup                     Cleanup environment for given <profile> (unmount all and delete Alarm image only after confirmation)
  help                        Displays help for a command
  list                        Lists commands
 config
  config:dump                 Dump configuration for given <profile>
 image
  image:download              Download Alarm image for given <profile> (if needed)
  image:extract               Extract Alarm image content to target storage for given <profile>
  image:extract-post          Performs post-extract tasks for given <profile> (update target /etc/fstab)
 requirements
  requirements:check          Check requirements
 self
  self:update                 [update] Updates Robo to the latest version.
 storage
  storage:device-init         Create partitions on device for given <profile>
  storage:format              Format partitions on target storage for given <profile>
  storage:image-init          Initialize the raw image used as storage for given <profile> (if any)
  storage:image-loop-mount    Mount image file on loop device for given <profile>
  storage:image-loop-unmount  Unmount image from loop device for given <profile>
  storage:mount               Mount all storage partitions to local directories for given <profile>
  storage:unmount             Unmount all storage partitions from local directories for given <profile>
```

### Examples

**Create a Raspberry Pi 1 SD card with 2 partitions (`/boot` and `/`)**

1. Copy the file `profiles/rpi1-device.yml` to `profiles/my-rpi1.yml`
   (optional: you might want to tweak other settings too)
1. Insert the SD card into your reader
1. Run `dmesg | tail` (or `lsblk` at your convenience) and retrieve the
   name of the newly added device
1. Edit the configuration and set `device` to the name of your device
   on the system
1. Run `build` to prepare the SD card:
    ```shell
    robo build my-rpi1
    ```
1. Eject your SD card safely
    ```shell
    eject /dev/sdX
    ```
1. You can now insert it into your Raspberry Pi 1 and boot it

**Create a Raspberry Pi 1 QEMU raw-image with 3 partitions (`/boot`,
  `/home` and `/`)**

1. Copy the file `profiles/rpi1-rawfile.sample2.yml` to
   `profiles/my-qemu-rpi1.yml` (optional: you might want to tweak other
   settings too)
1. Run `build` to prepare the image:
    ```shell
    robo build my-qemu-rpi1
    ```
1. You can now start your Raspberry Pi 1 QEMU emulator using this raw
image

> Usage of a raw Raspberry Pi image with QEMU is not covered here.
> 
> Check out https://github.com/paulden/raspbian-on-qemu-armv7 to learn
> more

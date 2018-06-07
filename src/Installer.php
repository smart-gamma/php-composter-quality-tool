<?php

namespace PHPComposter\GammaQualityTool;

use Composer\Util\Filesystem;
use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\FileLocator;

class Installer
{
    public static function persistConfig()
    {
        $filesystem = new Filesystem();
        $path       = __DIR__ . '/../../../../../app/Resources/GammaQualityTool';
        $filesystem->ensureDirectoryExists($path);

        try {
            $fileLocator = new FileLocator($path);
            $fileLocator->locate('config.yml');
        } catch (FileLocatorFileNotFoundException $e) {
            file_put_contents($path . '/config.yml', static::getConfig());
        }
    }

    /**
     * Generate the config file.
     *
     * @return string Generated Config file.
     */
    public static function getConfig()
    {
        $output = 'phpmd: false' . PHP_EOL;
        $output .= 'lint: false' . PHP_EOL;
        $output .= 'phpcs: true' . PHP_EOL;
        $output .= 'phpfixer: true' . PHP_EOL;
        $output .= 'units: false' . PHP_EOL;
        $output .= 'self_fix: true' . PHP_EOL;

        return $output;
    }
}

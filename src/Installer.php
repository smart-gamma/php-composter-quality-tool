<?php

namespace PHPComposter\GammaQualityTool;

use Composer\Util\Filesystem;
use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Process\ProcessBuilder;

class Installer
{
    public static function install()
    {
        self::composerInstall();
        self::persistConfig();
    }

    public static function composerInstall()
    {
        $processBuilder = new ProcessBuilder(['php', '../../../../vendor/bin/composer', '-n', 'install']);
        $processBuilder->setWorkingDirectory(__DIR__);
        $process = $processBuilder->getProcess();
        $process->run();
    }

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
        $output = 'phpmd: true' . PHP_EOL;
        $output .= 'lint: false' . PHP_EOL;
        $output .= 'phpcs: true' . PHP_EOL;
        $output .= 'phpcs_standard: PSR2' . PHP_EOL;
        $output .= 'phpfixer: true' . PHP_EOL;
        $output .= 'phpfixer_standard: Symfony' . PHP_EOL;
        $output .= 'units: false' . PHP_EOL;
        $output .= 'self_fix: true' . PHP_EOL;

        return $output;
    }
}

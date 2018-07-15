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
        self::persistPhpMDConfig();
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

    public static function persistPhpMDConfig()
    {
        $path = static::getWorkingDir();

        try {
            $fileLocator = new FileLocator($path);
            $fileLocator->locate('phpmd.xml');
        } catch (FileLocatorFileNotFoundException $e) {
            file_put_contents($path . '/phpmd.xml', static::getPhpMDConfig());
        }
    }

    /**
     * Generate the config file.
     *
     * @return string Generated Config content.
     */
    public static function getConfig()
    {
        $output = 'phpmd: true' . PHP_EOL;
        $output .= 'lint: true' . PHP_EOL;
        $output .= 'phpcs: true' . PHP_EOL;
        $output .= 'phpcs_standard: PSR2' . PHP_EOL;
        $output .= 'phpfixer: true' . PHP_EOL;
        $output .= 'phpfixer_standard: Symfony' . PHP_EOL;
        $output .= 'phpspec: false' . PHP_EOL;
        $output .= 'self_fix: true' . PHP_EOL;
        $output .= 'exclude_dirs: /app,/bin' . PHP_EOL;

        return $output;
    }

    /**
     * Read PhpMD config file.
     *
     * @return string Generated PhpMD Config content.
     */
    public static function getPhpMDConfig()
    {
        $fileLocator        = new FileLocator(__DIR__ .'/../');
        $configFile         = $fileLocator->locate('phpmd.xml');

        return file_get_contents($configFile);
    }

    /**
     * @return string
     */
    private static function getWorkingDir()
    {
        return __DIR__ . '/../../../../..';
    }
}

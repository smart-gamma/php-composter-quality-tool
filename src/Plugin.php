<?php

namespace PHPComposter\GammaQualityTool;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Symfony\Component\Config\FileLocator;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The name of the current package.
     * Used in error output.
     *
     * @var string
     */
    const PACKAGE_NAME = 'php-composter/php-composter-quality-tool';

    /**
     * Instance of the IO interface.
     *
     * @var IOInterface
     *
     * @since 0.1.0
     */
    protected static $io;

    /**
     * Get the event subscriber configuration for this plugin.
     *
     * @return array<string,string> The events to listen to, and their associated handlers.
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'persistConfig',
            ScriptEvents::POST_UPDATE_CMD  => 'persistConfig',
        );
    }

    /**
     * Persist the stored configuration.
     *
     * @since 0.1.0
     *
     * @param Event $event Event that was triggered.
     */
    public static function persistConfig(Event $event)
    {
        $filesystem = new Filesystem();
        $path       = __DIR__ . '/../../../../../app/Resources/GammaQualityTool';
        $filesystem->ensureDirectoryExists($path);

        $fileLocator = new FileLocator($path);
        $configFiles = $fileLocator->locate('config.yml', null, false);

        if(!isset($configFiles[0])) {
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

    /**
     * @param Composer    $composer Reference to the Composer instance.
     * @param IOInterface $io       Reference to the IO interface.
     */
    public function activate(Composer $composer, IOInterface $io)
    {
    }
}

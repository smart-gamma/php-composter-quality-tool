<?php
/**
 * PHP Composter Smart Gamma Quality Tool plugin
 *
 * @package   PHPComposter\GammaQualityTool
 * @author    Evgeniy Kuzmin <evgeniy.k@smart-gamma.com>
 * @license   MIT
 * @link      https://www.smart-gamma.com
 */
namespace PHPComposter\GammaQualityTool;

use PHPComposter\PHPComposter\BaseAction;

class Sniffer extends BaseAction
{
    const IS_MIRROR_STAGE = false;

    public function preCommit()
    {
        $files = $this->getStagedFiles('', self::IS_MIRROR_STAGE);
        var_dump($files);

        $app = new CodeQualityTool($files);
        //$app->doRun(new ArgvInput(), new ConsoleOutput());
        $app->run();

        if (!$app->isCodeStyleViolated()) {
            exit(0);
        } else {
            echo 'PHP Code Sniffer found errors! Aborting Commit.' . PHP_EOL;
            exit(1);
        }
    }
}

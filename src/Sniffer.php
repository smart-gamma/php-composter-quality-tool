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
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;


class Sniffer extends BaseAction
{
    public function preCommit()
    {
        $app = new CodeQualityTool();
        $app->doRun(new ArgvInput(), new ConsoleOutput());

        if (!$app->isCodeStyleViolated()) {
            exit(0);
        } else {
            echo 'PHP Code Sniffer found errors! Aborting Commit.' . PHP_EOL;
            exit(1);
        }
    }
}

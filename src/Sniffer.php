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
    public function preCommit()
    {
        $app = new CodeQualityTool();
        $app->run();
    }
}

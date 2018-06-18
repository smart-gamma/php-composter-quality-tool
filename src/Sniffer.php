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
    const ERROR_CODE = 1;

    public function preCommit()
    {
        $files = $this->getStagedFiles('', self::IS_MIRROR_STAGE);
        $app = new CodeQualityTool($files, $this->root);
        $app->run();

        if (!$app->isCodeStyleViolated()) {
            $this->success('Well done');
        } else {
            $this->error('PHP Code Sniffer found errors! Aborting Commit.', self::ERROR_CODE);
        }
    }
}

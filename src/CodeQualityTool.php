<?php

namespace PHPComposter\GammaQualityTool;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Question\ConfirmationQuestion;

define('VENDOR_DIR', __DIR__ . '/../../../../../vendor');

class CodeQualityTool extends Application
{
    const IS_PHPMD    = false;
    const IS_LINT     = false;
    const IS_PHPCS    = true;
    const IS_PHPFIXER = true;
    const IS_UNITS    = false;
    const IS_SELF_FIX = true;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var array
     */
    private $commitedFiles = []

    /**
     * @var array
     */
    private $autoFixedFiles = [];

    private $isCodeStyleViolated = false;

    const PHP_FILES_IN_SRC      = '/^src\/(.*)(\.php)$/';
    const PHP_FILES_IN_CLASSES  = '/^classes\/(.*)(\.php)$/';
    const PHP_FILES_IN_FEATURES = '/^features\/(.*)(\.php)$/';

    public function __construct()
    {
        parent::__construct('Smart Gamma Quality Tool', '1.0.0');
    }

    public function isCodeStyleViolated()
    {
        return $this->isCodeStyleViolated;
    }


    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     * @throws \Exception
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->isCodeStyleViolated = false;
        $this->input               = $input;
        $this->output              = $output;
        $this->output->writeln('<fg=white;options=bold;bg=red>Code Quality Tool</fg=white;options=bold;bg=red>');
        $this->output->writeln('<info>Fetching files</info>');
        $this->commitedFiles = $this->extractCommitedFiles();

        if (self::IS_LINT ? !$this->phpLint($this->commitedFiles) : false) {
            throw new \Exception('There are some PHP syntax errors!');
        }

        if (self::IS_PHPFIXER ? $this->isCodeStyleViolated = !$this->checkCodeStylePhpFixer($this->commitedFiles) : false) {
            $this->output->writeln('<error>There are coding standards violations by php-cs-fixer!</error>');
        }

        if (self::IS_PHPCS ? $this->isCodeStyleViolated = !$this->checkCodeStylePhpCS($this->commitedFiles) : false) {
            $this->output->writeln('<error>There are PHPCS coding standards violations!</error>');
        }

        if ($this->isCodeStyleViolated) {
            $helper   = $this->getHelperSet()->get('question');
            $question = new ConfirmationQuestion('Continue auto fix with php-cs-fixer?', false);
            if ($helper->ask($this->input, $this->output, $question)) {
                $this->output->writeln('<info>Autofixing code style</info>');
                $this->fixCodeStylePhpFixer($this->commitedFiles);
                $this->gitAddToCommitAutofixedFiles();
            }
            $question = new ConfirmationQuestion('Restart check again?', false);
            if ($helper->ask($this->input, $this->output, $question)) {
                $this->run();
            }
        }

        if (self::IS_PHPMD ? !$this->phPmd($this->commitedFiles) : false) {
            throw new \Exception(sprintf('There are PHPMD violations!'));
        }

        $this->output->writeln('<info>Well done!</info>');
    }

    /**
     * @return array
     */
    private function extractCommitedFiles()
    {
        $output  = array();
        $against = 'HEAD';
        exec("git diff-index --name-status $against | egrep '^(A|M)' | awk '{print $2;}'", $output);

        return $output;
    }

    /**
     * @throws \Exception
     */
    private function phpLint(array $files): bool
    {
        $this->output->writeln('<info>Running PHPLint</info>');
        $needle  = '/(\.php)|(\.inc)$/';
        $succeed = true;
        foreach ($files as $file) {
            if (!preg_match($needle, $file)) {
                continue;
            }
            $processBuilder = new ProcessBuilder(array('php', '-l', $file));
            $process        = $processBuilder->getProcess();
            $process->run();
            if (!$process->isSuccessful()) {
                $this->output->writeln($file);
                $this->output->writeln(sprintf('<error>%s</error>', trim($process->getErrorOutput())));
                $succeed = false;
            }
        }

        return $succeed;
    }

    private function phPmd(array $files): bool
    {
        $this->output->writeln('<info>Checking code mess with PHPMD</info>');
        $needle   = self::PHP_FILES_IN_SRC;
        $succeed  = true;
        $rootPath = realpath(__DIR__ . '/../');
        $fileRule = VENDOR_DIR . '/../phpmd.xml';
        if (file_exists($fileRule)) {
            $rule = $fileRule;
        } else {
            $rule = 'codesize,unusedcode,naming';
        }
        foreach ($files as $file) {
            if (!preg_match($needle, $file)) {
                continue;
            }
            $processBuilder = new ProcessBuilder(['php', VENDOR_DIR . '/../bin/phpmd', $file, 'text', $rule]);
            $processBuilder->setWorkingDirectory($rootPath);
            $process = $processBuilder->getProcess();
            $process->run();
            if (!$process->isSuccessful()) {
                $this->output->writeln($file);
                $this->output->writeln(sprintf('<error>%s</error>', trim($process->getErrorOutput())));
                $this->output->writeln(sprintf('<info>%s</info>', trim($process->getOutput())));
                $succeed = false;
            }
        }

        return $succeed;
    }

    private function unitTests(): bool
    {
        $filePhpunit = VENDOR_DIR . '/../phpunit.xml';
        if (file_exists($filePhpunit)) {
            $processBuilder = new ProcessBuilder(array('php', VENDOR_DIR . '/bin/phpunit'));
            $processBuilder->setWorkingDirectory(__DIR__ . '/../..');
            $processBuilder->setTimeout(3600);
            $phpunit = $processBuilder->getProcess();
            $phpunit->run(
                function ($type, $buffer) {
                    $this->output->write($buffer);
                }
            );

            return $phpunit->isSuccessful();
        }
        $this->output->writeln(sprintf('<fg=yellow>%s</>', 'Not PHPUnit!'));

        return true;
    }

    private function checkCodeStylePhpFixer(array $files): bool
    {
        $this->output->writeln('<info>Checking code style by php-cs-fixer</info>');
        $succeed = true;
        foreach ($files as $file) {
            $classesFile = preg_match(self::PHP_FILES_IN_CLASSES, $file);
            $srcFile     = preg_match(self::PHP_FILES_IN_SRC, $file);
            $featureFile = preg_match(self::PHP_FILES_IN_FEATURES, $file);
            if (!$classesFile && !$srcFile && !$featureFile) {
                continue;
            }
            $processBuilder = new ProcessBuilder(array('php', VENDOR_DIR . '/bin/php-cs-fixer', '--dry-run', '--diff', '--verbose', 'fix', $file, '--rules=@PSR2'));
            $processBuilder->setWorkingDirectory($this->getWorkingDir());
            $phpCsFixer = $processBuilder->getProcess();
            $phpCsFixer->enableOutput();
            $phpCsFixer->run();
            if (!$phpCsFixer->isSuccessful()) {
                $this->output->writeln(sprintf('<error>%s</error>', trim($phpCsFixer->getOutput())));
                $succeed = false;
            }
        }

        return $succeed;
    }

    private function fixCodeStylePhpFixer(array $files): bool
    {
        $this->output->writeln('<info>Fixing code style by php-cs-fixer</info>');
        $succeed = true;
        foreach ($files as $file) {
            $classesFile = preg_match(self::PHP_FILES_IN_CLASSES, $file);
            $srcFile     = preg_match(self::PHP_FILES_IN_SRC, $file);
            $featureFile = preg_match(self::PHP_FILES_IN_FEATURES, $file);
            if (!$classesFile && !$srcFile && !$featureFile) {
                continue;
            }
            $processBuilder = new ProcessBuilder(array('php', VENDOR_DIR . '/bin/php-cs-fixer', 'fix', $file, '--rules=@PSR2'));
            $processBuilder->setWorkingDirectory($this->getWorkingDir());
            $phpCsFixer = $processBuilder->getProcess();
            $phpCsFixer->enableOutput();
            $phpCsFixer->run();
            if (!$phpCsFixer->isSuccessful()) {
                $this->output->writeln(sprintf('<error>%s</error>', trim($phpCsFixer->getOutput())));
                $succeed = false;
            } else {
                $this->output->writeln($file);
                $this->autoFixedFiles[] = $file;
            }
        }

        return $succeed;
    }

    private function checkCodeStylePhpCS(array $files): bool
    {
        $this->output->writeln('<info>Checking code style with PHPCS</info>');

        $succeed  = true;
        $standard = 'PSR2';

        foreach ($files as $file) {
            $srcFile     = preg_match(self::PHP_FILES_IN_SRC, $file);
            $featureFile = preg_match(self::PHP_FILES_IN_FEATURES, $file);
            if (!$srcFile && !$featureFile) {
                continue;
            }

            $processBuilder = new ProcessBuilder(array('php', VENDOR_DIR . '/bin/phpcs', '-n', '--standard=' . $standard, $file));
            $processBuilder->setWorkingDirectory($this->getWorkingDir());
            $phpCsFixer = $processBuilder->getProcess();
            $phpCsFixer->run();
            if (!$phpCsFixer->isSuccessful()) {
                $this->output->writeln(sprintf('<error>%s</error>', trim($phpCsFixer->getOutput())));
                $succeed = false;
            }
        }

        return $succeed;
    }

    private function getWorkingDir(): string
    {
        return __DIR__ . '/../../../../../';
    }

    private function gitAddToCommitAutofixedFiles()
    {
        foreach ($this->commitedFiles as $file) {
            $this->output->writeln("git add " . $this->getWorkingDir() . $file);
            exec("git add " . $this->getWorkingDir() . $file);
        }
    }
}
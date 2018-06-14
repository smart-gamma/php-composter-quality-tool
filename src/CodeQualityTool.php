<?php

namespace PHPComposter\GammaQualityTool;

use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

define('VENDOR_DIR', __DIR__ . '/../../../../../vendor');

class CodeQualityTool extends Application
{
    /**
     * @var array
     */
    private $configValues = [
        'phpmd'    => true,
        'lint'     => false,
        'phpcs'    => true,
        'phpfixer' => true,
        'units'    => false,
        'self_fix' => true,
    ];

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
    private $commitedFiles = [];

    /**
     * @var array
     */
    private $autoFixedFiles = [];

    private $isCodeStyleViolated = false;

    const PHP_FILES_IN_SRC      = '/^src\/(.*)(\.php)$/';
    const PHP_FILES_IN_CLASSES  = '/^classes\/(.*)(\.php)$/';
    const PHP_FILES_IN_FEATURES = '/^features\/(.*)(\.php)$/';

    public function __construct(array $commitedFiles)
    {
        $this->commitedFiles = $commitedFiles;

        $this->configure();
        parent::__construct('Smart Gamma Quality Tool', '1.0.0');
    }

    private function configure()
    {
        try {
            $fileLocator        = new FileLocator(VENDOR_DIR . '/../app/Resources/GammaQualityTool');
            $configFile        = $fileLocator->locate('config.yml');
            $this->configValues = Yaml::parse(file_get_contents($configFile));
        } catch (FileLocatorFileNotFoundException $e) {
        }
    }

    public function isCodeStyleViolated(): bool
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

        if ($this->configValues['lint'] ? !$this->phpLint($this->commitedFiles) : false) {
            throw new \Exception('There are some PHP syntax errors!');
        }

        if ($this->configValues['phpfixer'] ? $this->isCodeStyleViolated = !$this->checkCodeStylePhpFixer($this->commitedFiles) : false) {
            $this->output->writeln('<error>There are coding standards violations by php-cs-fixer!</error>');
        }

        if ($this->configValues['phpcs'] ? $this->isCodeStyleViolated = !$this->checkCodeStylePhpCS($this->commitedFiles) : false) {
            $this->output->writeln('<error>There are PHPCS coding standards violations!</error>');
        }

        $helper = $this->getHelperSet()->get('question');

        if ($this->isCodeStyleViolated) {
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

        if ($this->configValues['phpmd'] ? !$this->phPmd($this->commitedFiles) : false) {
            $this->output->writeln('<error>There are PHPMD violations! Resolve them manually and type \'y\' or let them be added "as is"  - type \'n\'</error>');
            $question = new ConfirmationQuestion('Restart check again?', false);
            if ($helper->ask($this->input, $this->output, $question)) {
                $this->gitAddToCommitAutofixedFiles();
                $this->run();
            }
        }

        $this->output->writeln('<info>Well done!</info>');
    }

    /**
     * @return array
     */
    private function extractCommitedFiles(): array
    {
        $output  = array();
        $against = 'HEAD';
        //exec("git diff-index --name-status $against | egrep '^(A|M)' | awk '{print $2;}'", $output);

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
            $processBuilder = new ProcessBuilder(['php', VENDOR_DIR . '/bin/phpmd', $file, 'text', $rule]);
            $processBuilder->setWorkingDirectory($this->getWorkingDir());
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
            echo  VENDOR_DIR . '/bin/phpcs', '-n', '--standard=' . $standard;
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
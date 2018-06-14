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

class CodeQualityTool extends Application
{
    /**
     * @var array
     */
    private $configValues = [
        'phpmd'             => true,
        'lint'              => false,
        'phpcs'             => true,
        'phpcs_standard'    => 'PSR2',
        'phpfixer'          => true,
        'phpfixer_standard' => 'Symfony',
        'units'             => false,
        'self_fix'          => true,
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

    /**
     * @var bool
     */
    private $isCodeStyleViolatedByFixer = false;

    /**
     * @var bool
     */
    private $isCodeStyleViolatedByCS = false;

    /**
     * @var string
     */
    private $workingDir;


    public function __construct(array $commitedFiles, string $workingDir)
    {
        $this->commitedFiles = $commitedFiles;
        $this->workingDir    = $workingDir;
        $this->configure();
        parent::__construct('Smart Gamma Quality Tool', '1.0.4');
    }

    private function configure()
    {
        try {
            $fileLocator        = new FileLocator($this->getWorkingDir() . '/app/Resources/GammaQualityTool');
            $configFile         = $fileLocator->locate('config.yml');
            $this->configValues = Yaml::parse(file_get_contents($configFile));
        } catch (FileLocatorFileNotFoundException $e) {
        }
    }

    public function isCodeStyleViolated(): bool
    {
        return $this->isCodeStyleViolatedByFixer || $this->isCodeStyleViolatedByCS;
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

        if ($this->configValues['phpfixer'] ? $this->isCodeStyleViolatedByFixer = !$this->checkCodeStylePhpFixer($this->commitedFiles) : false) {
            $this->output->writeln('<error>There are coding standards violations by php-cs-fixer!</error>');
        }

        if ($this->configValues['phpcs'] ? $this->isCodeStyleViolatedByCS = !$this->checkCodeStylePhpCS($this->commitedFiles) : false) {
            $this->output->writeln('<error>There are PHPCS coding standards violations!</error>');
        }

        $helper = $this->getHelperSet()->get('question');

        if ($this->isCodeStyleViolated()) {
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
        $succeed  = true;
        $fileRule = $this->getWorkingDir() . '/phpmd.xml';

        if (file_exists($fileRule)) {
            $rule = $fileRule;
        } else {
            $rule = 'codesize,unusedcode,naming';
        }

        foreach ($files as $file) {
            $processBuilder = new ProcessBuilder(['php', './vendor/bin/phpmd', $file, 'text', $rule]);
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

    private function checkCodeStylePhpFixer(array $files): bool
    {
        $this->output->writeln('<info>Checking code style by php-cs-fixer</info>');
        $succeed = true;
        foreach ($files as $file) {
            $processBuilder = new ProcessBuilder(array('php', './vendor/bin/php-cs-fixer', '--dry-run', '--diff', '--verbose', 'fix', $file, '--rules=@' . $this->configValues['phpfixer_standard']));
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
            $processBuilder = new ProcessBuilder(array('php', './vendor/bin/php-cs-fixer', 'fix', $file, '--rules=@' . $this->configValues['phpfixer_standard']));
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

        $succeed = true;

        foreach ($files as $file) {
            $processBuilder = new ProcessBuilder(array('php', './vendor/bin/phpcs', '-n', '--standard=' . $this->configValues['phpcs_standard'], $file));
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
        return $this->workingDir;
    }

    private function gitAddToCommitAutofixedFiles()
    {
        foreach ($this->commitedFiles as $file) {
            $this->output->writeln("git add " . $file);
            exec("git add " . $file);
        }
    }
}

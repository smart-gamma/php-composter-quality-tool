<?php

namespace PHPComposter\GammaQualityTool;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
    const APP_NAME               = 'Smart Gamma Quality Tool';
    const APP_VERSION            = 'v0.1.7';
    const APP_CONFIG_FOLDER_PATH = '/app/Resources/GammaQualityTool';
    const APP_CONFIG_FILE_NAME   = 'config.yml';

    /**
     * @var array
     */
    private $defaultConfigValues = [
        'phpmd'             => true,
        'lint'              => true,
        'phpcs'             => true,
        'phpcs_standard'    => 'PSR2',
        'phpfixer'          => true,
        'phpfixer_standard' => 'Symfony',
        'phpspec'           => false,
        'self_fix'          => true,
        'exclude_dirs'      => '/app,/bin',
    ];

    /**
     * @var array
     */
    private $configValues = [];

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
    private $trackedFiles = [];

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
        parent::__construct(self::APP_NAME, self::APP_VERSION);
    }

    private function filterExcludedFiles($files)
    {
        $excludeDirs = explode(",", $this->getConfig('exclude_dirs'));

        return array_filter(
            $files,
            function ($file) use ($excludeDirs) {
                $expr = '!^' . $this->getWorkingDir() . '(' . implode('|', $excludeDirs) . ')/(.*?)$!';

                return preg_match('/(\.php)$/', $file) && !preg_match($expr, $file);
            }
        );
    }

    private function configure()
    {
        try {
            $fileLocator        = new FileLocator($this->getWorkingDir() . self::APP_CONFIG_FOLDER_PATH);
            $configFile         = $fileLocator->locate(self::APP_CONFIG_FILE_NAME);
            $this->configValues = Yaml::parse(file_get_contents($configFile));
        } catch (FileLocatorFileNotFoundException $e) {
            $this->configValues = $this->defaultConfigValues;
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
        $this->configure();
        $this->trackedFiles = $this->filterExcludedFiles($this->commitedFiles);

        $this->output->writeln(sprintf('<fg=white;options=bold;bg=blue>%s %s</fg=white;options=bold;bg=blue>', self::APP_NAME, self::APP_VERSION));
        $this->output->writeln('<info>Fetching files</info>');

        if ($this->getConfig('lint') ? !$this->phpLint($this->trackedFiles) : false) {
            throw new \Exception('There are some PHP syntax errors!');
        }

        if ($this->getConfig('phpfixer') ? $this->isCodeStyleViolatedByFixer = !$this->checkCodeStylePhpFixer($this->trackedFiles) : false) {
            $this->output->writeln('<error>There are coding standards violations by php-cs-fixer!</error>');
        }

        if ($this->getConfig('phpcs') ? $this->isCodeStyleViolatedByCS = !$this->checkCodeStylePhpCS($this->trackedFiles) : false) {
            $this->output->writeln('<error>There are PHPCS coding standards violations!</error>');
        }

        $helper = $this->getHelperSet()->get('question');

        if ($this->isCodeStyleViolated()) {
            $question = new ConfirmationQuestion('Continue auto fix with php-cs-fixer?', false);
            if ($helper->ask($this->input, $this->output, $question)) {
                $this->output->writeln('<info>Autofixing code style</info>');
                $this->fixCodeStylePhpFixer($this->trackedFiles);
                $this->gitAddToCommitAutofixedFiles();
            }

            $question = new ConfirmationQuestion('Restart check again?', false);
            if ($helper->ask($this->input, $this->output, $question)) {
                $this->run();
            }
        }

        if ($this->getConfig('phpmd') ? !$this->phPmd($this->trackedFiles) : false) {
            $this->output->writeln('<error>There are PHPMD violations! Resolve them manually and type \'y\' or let them be added "as is"  - type \'n\'</error>');
            $question = new ConfirmationQuestion('Restart check again?', false);

            if ($helper->ask($this->input, $this->output, $question)) {
                $this->gitAddToCommitAutofixedFiles();
                $this->run();
            }
        }

        if ($this->getConfig('phpspec') ? !$this->phpSpec() : false) {
            throw new \Exception('There are some phpSpec tests broken');
        }

        $this->output->writeln('<info>Well done!</info>');
    }

    private function phpLint(array $files): bool
    {
        $this->output->writeln('<info>Running PHPLint</info>');
        $needle  = '/(\.php)|(\.inc)$/';
        $succeed = true;
        foreach ($files as $file) {
            if (!preg_match($needle, $file)) {
                continue;
            }
            $processBuilder = new ProcessBuilder(
                [
                    'php',
                    '-l',
                    $file,
                ]
            );
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

    private function phpSpec(): bool
    {
        $this->output->writeln('<info>Running phpSpec tests</info>');
        $succeed = true;

        $processBuilder = new ProcessBuilder(
            [
                'vendor/bin/phpspec',
                'run',
            ]
        );
        $process        = $processBuilder->getProcess();
        $process->run();
        $this->output->writeln($process->getOutput());

        if (!$process->isSuccessful()) {
            $this->output->writeln(sprintf('<error>%s</error>', trim($process->getErrorOutput())));
            $succeed = false;
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
            $processBuilder = new ProcessBuilder(
                [
                    'php',
                    './vendor/bin/phpmd',
                    $file,
                    'text',
                    $rule,
                ]
            );
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
            $processBuilder = new ProcessBuilder(
                [
                    'php',
                    './vendor/bin/php-cs-fixer',
                    '--dry-run',
                    '--diff',
                    '--verbose',
                    'fix',
                    $file,
                    '--rules=@' . $this->getConfig('phpfixer_standard'),
                ]
            );
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
            $processBuilder = new ProcessBuilder(
                [
                    'php',
                    './vendor/bin/php-cs-fixer',
                    'fix',
                    $file,
                    '--rules=@' . $this->getConfig('phpfixer_standard'),
                ]
            );
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
            $processBuilder = new ProcessBuilder(
                [
                    'php',
                    './vendor/bin/phpcs',
                    '-n',
                    '--standard=' . $this->getConfig('phpcs_standard'),
                    $file,
                ]
            );
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

    private function getConfig(string $key): string
    {
        $this->resolveCurrentConfigValues($key);

        if (!in_array($key, $this->configValues)) {
            throw new InvalidConfigurationException(sprintf('Requested configuration key "%s" does not defined in the quality tool', $key));
        }

        return $this->configValues[$key];
    }

    private function resolveCurrentConfigValues(string $key)
    {
        if (!array_key_exists($key, $this->configValues) && array_key_exists($key, $this->defaultConfigValues)) {
            $this->configValues[$key] = $this->defaultConfigValues[$key];
            $defaultValueOutput       = is_bool($this->defaultConfigValues[$key]) ? var_export($this->defaultConfigValues[$key], 1) : $this->defaultConfigValues[$key];
            $this->output->writeln(
                sprintf('<comment>Configuration key "%s" is not defined at %s, using default: %s</comment>', $key, self::APP_CONFIG_FOLDER_PATH . self::APP_CONFIG_FILE_NAME, $key . '=' . $defaultValueOutput)
            );
        }
    }
}

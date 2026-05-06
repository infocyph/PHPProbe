<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Filesystem\PhpFileFinder;
use Infocyph\PHPProbe\Process\ProcessResult;
use Infocyph\PHPProbe\Process\ProcRunner;

final class SyntaxChecker
{
    private CliOptions $cli;

    public function __construct()
    {
        $this->cli = new CliOptions();
    }

    /**
     * @param list<string> $paths
     */
    public function run(array $paths): int
    {
        try {
            $options = $this->parseArgs($paths);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }

        if ($options['help']) {
            return $this->help();
        }

        $files = (new PhpFileFinder())->find($options['paths'], $options['excludes']);

        if ($files === []) {
            fwrite(STDOUT, 'No PHP files found.' . PHP_EOL);

            return 0;
        }

        return $this->lintFiles($files);
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe syntax [options] [paths...]',
            '',
            'Options:',
            '  --config=FILE                  read PHPProbe checker settings',
            '  --preset=NAME                  apply preset: phpstorm, standard, or strict',
            '  --exclude=PATH                 skip a path (repeatable)',
            '  --help                         show this help',
        ]) . PHP_EOL);

        return 0;
    }

    private function lintFile(string $file): ?string
    {
        $result = (new ProcRunner())->run([PHP_BINARY, '-d', 'display_errors=1', '-l', $file]);

        if (!$result instanceof ProcessResult) {
            return 'Could not start PHP lint process';
        }

        if ($result->successful()) {
            return null;
        }

        $message = trim($result->stdout . PHP_EOL . $result->stderr);

        return $message !== '' ? $message : 'Unknown lint failure';
    }

    /**
     * @param list<string> $files
     */
    private function lintFiles(array $files): int
    {
        $failures = [];

        foreach ($files as $file) {
            $failure = $this->lintFile($file);

            if (is_string($failure)) {
                $failures[] = [$file, $failure];
            }
        }

        if ($failures === []) {
            fwrite(STDOUT, Ansi::color(sprintf('Syntax OK: %d PHP files checked.', count($files)), 'green', STDOUT) . PHP_EOL);

            return 0;
        }

        fwrite(STDERR, Ansi::color(sprintf('Syntax errors in %d file(s):', count($failures)), 'red', STDERR) . PHP_EOL);

        foreach ($failures as [$file, $message]) {
            fwrite(STDERR, '  ' . Ansi::color($file, 'cyan', STDERR) . PHP_EOL);

            foreach (preg_split('/\R/', trim($message)) ?: [] as $line) {
                if ($line !== '') {
                    fwrite(STDERR, '    ' . $line . PHP_EOL);
                }
            }
        }

        return 1;
    }

    /**
     * @param list<string> $args
     * @return array{help:bool,config:string,paths:list<string>,excludes:list<string>}
     */
    private function parseArgs(array $args): array
    {
        $options = [
            'help' => false,
            'config' => Paths::config('phpprobe.json'),
            'paths' => [],
            'excludes' => [],
        ];
        $options['config'] = $this->cli->configPath($args, $options['config']);
        $config = $this->cli->mergeConfigWithPreset(PhpProbeConfig::fromFile($options['config']), $this->cli->presetName($args));
        $options = $config->applySyntaxOptions($options);
        $configuredPaths = $options['paths'];
        $options['paths'] = [];
        $index = 0;
        $argCount = count($args);
        $collectingPathsOnly = false;

        while ($index < $argCount) {
            $arg = $args[$index];

            if ($collectingPathsOnly) {
                $options['paths'][] = $arg;
                $index++;

                continue;
            }

            if ($arg === '--') {
                $collectingPathsOnly = true;
                $index++;

                continue;
            }

            if (!$this->cli->skipConfig($args, $index, $arg)
                && !$this->cli->skipPreset($args, $index, $arg)
                && !$this->parseCliOption($args, $index, $options, $arg)) {
                if (str_starts_with($arg, '-')) {
                    throw new \InvalidArgumentException(sprintf('Unknown option for syntax command: %s', $arg));
                }

                $options['paths'][] = $arg;
            }

            $index++;
        }

        if ($options['paths'] === []) {
            $options['paths'] = $configuredPaths;
        }

        return $options;
    }

    /**
     * @param list<string> $args
     * @param array{help:bool,config:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseCliOption(array $args, int &$index, array &$options, string $arg): bool
    {
        if ($this->cli->parseExclude($args, $index, $options, $arg)) {
            return true;
        }

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;

            return true;
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Process\ProcessResult;
use Infocyph\PHPProbe\Process\ProcRunner;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

final class SyntaxChecker
{
    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        return CheckerRuntime::guarded(fn(): int => $this->runWithOptions($this->parseArgs($args)));
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe syntax [options] [paths...]',
            '',
            'Options:',
            '  --config=FILE                    read PHPProbe checker settings',
            '  --preset=NAME                    apply preset: default, standard, ci, or strict',
            '  --exclude=PATH                   skip a path (repeatable)',
            '  --format=text|json|markdown|sarif|github output format (default: text)',
            '  --color=auto|always|never       ANSI color mode (default: auto)',
            '  --json                           alias for --format=json',
            '  --summary-json=FILE              write machine-readable run summary',
            '  --changed-only                   scan only changed PHP files from Git diff',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --parallel=N                     parallel lint worker count (default: 1)',
            '  --help                           show this help',
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
     * @return array{files_checked:int,failures:list<array{file:string,message:string}>}
     */
    private function lintFiles(array $files, int $parallel): array
    {
        if ($parallel <= 1 || count($files) <= 1) {
            return $this->lintFilesSequential($files);
        }

        return $this->lintFilesParallel($files, $parallel);
    }

    /**
     * @param list<string> $files
     * @return array{files_checked:int,failures:list<array{file:string,message:string}>}
     */
    private function lintFilesParallel(array $files, int $parallel): array
    {
        $queue = array_values($files);
        $limit = max(1, min($parallel, count($queue)));
        $running = [];
        $failures = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $limit) {
                $file = array_shift($queue);

                if (!is_string($file)) {
                    continue;
                }

                $running[] = $this->startLintProcess($file);
            }

            foreach ($running as $key => $job) {
                $job['stdout'] .= stream_get_contents($job['pipes'][1]) ?: '';
                $job['stderr'] .= stream_get_contents($job['pipes'][2]) ?: '';
                $status = proc_get_status($job['process']);

                if (($status['running'] ?? false) === true) {
                    $running[$key] = $job;

                    continue;
                }

                fclose($job['pipes'][0]);
                fclose($job['pipes'][1]);
                fclose($job['pipes'][2]);
                $closeExitCode = proc_close($job['process']);
                $statusExitCode = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : -1;
                $exitCode = $statusExitCode !== -1 ? $statusExitCode : $closeExitCode;

                if ($exitCode !== 0) {
                    $message = trim($job['stdout'] . PHP_EOL . $job['stderr']);
                    $failures[] = [
                        'file' => $job['file'],
                        'message' => $message !== '' ? $message : 'Unknown lint failure',
                    ];
                }

                unset($running[$key]);
            }

            if ($running !== []) {
                usleep(10000);
            }
        }

        return ['files_checked' => count($files), 'failures' => array_values($failures)];
    }

    /**
     * @param list<string> $files
     * @return array{files_checked:int,failures:list<array{file:string,message:string}>}
     */
    private function lintFilesSequential(array $files): array
    {
        $failures = [];

        foreach ($files as $file) {
            $failure = $this->lintFile($file);

            if (is_string($failure)) {
                $failures[] = ['file' => $file, 'message' => $failure];
            }
        }

        return ['files_checked' => count($files), 'failures' => $failures];
    }

    /**
     * @param array{help:bool,format:string,summaryJson:string,changedOnly:bool,changedBase:string,parallel:int,config:string,paths:list<string>,excludes:list<string>} $options
     * @return array{0:array{files_checked:int,failures:list<array{file:string,message:string}>},1:bool,2:int}
     */
    private function lintOutcome(array $options): array
    {
        $files = CheckerRuntime::phpFiles($options);
        $result = $files === []
            ? ['files_checked' => 0, 'failures' => []]
            : $this->lintFiles($files, $options['parallel']);
        $failed = $result['failures'] !== [];

        return [$result, $failed, $failed ? 1 : 0];
    }

    /**
     * @param list<string> $args
     * @return array{help:bool,format:string,summaryJson:string,changedOnly:bool,changedBase:string,parallel:int,config:string,paths:list<string>,excludes:list<string>}
     */
    private function parseArgs(array $args): array
    {
        $cli = new CliOptions();
        $options = [
            'help' => false,
            'format' => 'text',
            'color' => 'auto',
            'summaryJson' => '',
            'changedOnly' => false,
            'changedBase' => '',
            'parallel' => 1,
            'textColorSuccess' => 'green',
            'textColorError' => 'red',
            'textColorFile' => 'cyan',
            'config' => Paths::config('phpprobe.json'),
            'paths' => [],
            'excludes' => [],
        ];
        $options['config'] = $cli->configPath($args, $options['config']);
        $config = $cli->mergeConfigWithPreset(PhpProbeConfig::fromFile($options['config']), $cli->presetName($args));
        $options = $config->applySyntaxOptions($options);
        $configuredPaths = $options['paths'];
        $cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg, $cli),
            'Unknown option for syntax command: %s',
        );

        $options['parallel'] = max(1, (int) $options['parallel']);

        return $options;
    }

    /**
     * @param list<string> $args
     * @param array{help:bool,format:string,summaryJson:string,changedOnly:bool,changedBase:string,parallel:int,config:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseCliOption(array $args, int &$index, array &$options, string $arg, CliOptions $cli): bool
    {
        if ($cli->parseCommonCheckerOptions($args, $index, $options, $arg, false)) {
            return true;
        }

        $parallel = $cli->optionValue($arg, '--parallel');

        if ($parallel !== null) {
            $options['parallel'] = max(1, (int) $parallel);

            return true;
        }

        return false;
    }

    /**
     * @param array{help:bool,format:string,summaryJson:string,changedOnly:bool,changedBase:string,parallel:int,config:string,paths:list<string>,excludes:list<string>} $options
     */
    private function runWithOptions(array $options): int
    {
        CheckerRuntime::applyColorMode($options);

        if ($options['help']) {
            return $this->help();
        }

        [$result, $failed, $exitCode] = $this->lintOutcome($options);

        $this->writeResult($result, $options, $failed);
        $this->writeSummaryJson($result, $options, $exitCode);

        return $exitCode;
    }

    /**
     * @return array{file:string,process:resource,pipes:array{0:resource,1:resource,2:resource},stdout:string,stderr:string}
     */
    private function startLintProcess(string $file): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-l', $file], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process) || !is_array($pipes)) {
            throw new \RuntimeException(sprintf('Could not start syntax lint process for %s', $file));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'file' => $file,
            'process' => $process,
            'pipes' => $pipes,
            'stdout' => '',
            'stderr' => '',
        ];
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     */
    private function summaryFooter(array $result, array $options, bool $failed): string
    {
        return sprintf(
            'Summary: files=%d failures=%d parallel=%d status=%s',
            $result['files_checked'],
            count($result['failures']),
            $options['parallel'],
            $failed ? 'FAIL' : 'PASS',
        );
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     */
    private function writeGithub(array $result): void
    {
        foreach ($result['failures'] as $failure) {
            fwrite(STDOUT, GithubAnnotation::emit(
                'error',
                'PHPProbe syntax',
                trim($failure['message']),
                $failure['file'],
                1,
            ) . PHP_EOL);
        }

        if ($result['failures'] === []) {
            fwrite(STDOUT, GithubAnnotation::emit('notice', 'PHPProbe syntax', 'No syntax errors found.') . PHP_EOL);
        }
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     */
    private function writeJson(array $result): void
    {
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     */
    private function writeMarkdown(array $result, bool $failed): void
    {
        $lines = [
            '# PHPProbe Syntax Report',
            '',
            sprintf('- Files checked: `%d`', $result['files_checked']),
            sprintf('- Failures: `%d`', count($result['failures'])),
            sprintf('- Status: `%s`', $failed ? 'FAIL' : 'PASS'),
            '',
        ];

        if ($result['failures'] === []) {
            $lines[] = 'No syntax errors found.';
        } else {
            $lines[] = '| File | Message |';
            $lines[] = '| --- | --- |';

            foreach ($result['failures'] as $failure) {
                $lines[] = sprintf(
                    '| `%s` | %s |',
                    $failure['file'],
                    str_replace('|', '\|', trim(preg_replace('/\s+/', ' ', $failure['message']) ?? $failure['message'])),
                );
            }
        }

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     * @param array{format:string} $options
     */
    private function writeResult(array $result, array $options, bool $failed): void
    {
        match ($options['format']) {
            'json' => $this->writeJson($result),
            'markdown' => $this->writeMarkdown($result, $failed),
            'sarif' => $this->writeSarif($result),
            'github' => $this->writeGithub($result),
            default => $this->writeText($result, $options, $failed),
        };
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     */
    private function writeSarif(array $result): void
    {
        $results = [];

        foreach ($result['failures'] as $failure) {
            $results[] = [
                'ruleId' => 'php_syntax_error',
                'level' => 'error',
                'message' => ['text' => trim($failure['message'])],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $failure['file']],
                        'region' => ['startLine' => 1],
                    ],
                ]],
            ];
        }

        fwrite(STDOUT, json_encode(Sarif::payload($results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     * @param array{summaryJson:string,parallel:int} $options
     */
    private function writeSummaryJson(array $result, array $options, int $exitCode): void
    {
        if ($options['summaryJson'] === '') {
            return;
        }

        SummaryJson::write($options['summaryJson'], [
            'checker' => 'syntax',
            'exit_code' => $exitCode,
            'files_checked' => $result['files_checked'],
            'failures' => count($result['failures']),
            'parallel' => $options['parallel'],
        ]);
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        if ($result['files_checked'] === 0) {
            fwrite(STDOUT, 'No PHP files found.' . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

            return;
        }

        if ($result['failures'] === []) {
            fwrite(STDOUT, Ansi::color(sprintf('Syntax OK: %d PHP files checked.', $result['files_checked']), (string) $options['textColorSuccess'], STDOUT) . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

            return;
        }

        fwrite(STDERR, Ansi::color(sprintf('Syntax errors in %d file(s):', count($result['failures'])), (string) $options['textColorError'], STDERR) . PHP_EOL);

        foreach ($result['failures'] as $failure) {
            fwrite(STDERR, '  ' . Ansi::color($failure['file'], (string) $options['textColorFile'], STDERR) . PHP_EOL);

            foreach (preg_split('/\R/', trim($failure['message'])) ?: [] as $line) {
                if ($line !== '') {
                    fwrite(STDERR, '    ' . $line . PHP_EOL);
                }
            }
        }

        fwrite(STDERR, $this->summaryFooter($result, $options, $failed) . PHP_EOL);
    }
}

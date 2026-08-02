<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\OptionValues;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Process\ProcessResult;
use Infocyph\PHPProbe\Process\ProcRunner;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\ProjectPath;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

/**
 * @phpstan-type SyntaxOptions array{help:bool,format:string,color:string,summaryJson:string,changedOnly:bool,changedBase:string,parallel:int,timeout:float,textColorSuccess:string,textColorError:string,textColorFile:string,config:string,paths:list<string>,excludes:list<string>}
 */
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
            '  --timeout=SECONDS                timeout per PHP lint process (default: 30)',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    private function lintFile(string $file, float $timeout): ?string
    {
        $result = new ProcRunner()->run([PHP_BINARY, '-d', 'display_errors=1', '-l', $file], timeout: $timeout);

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
    private function lintFiles(array $files, int $parallel, float $timeout): array
    {
        if ($parallel <= 1 || count($files) <= 1) {
            return $this->lintFilesSequential($files, $timeout);
        }

        return $this->lintFilesParallel($files, $parallel, $timeout);
    }

    /**
     * @param list<string> $files
     * @return array{files_checked:int,failures:list<array{file:string,message:string}>}
     */
    private function lintFilesParallel(array $files, int $parallel, float $timeout): array
    {
        $queue = $files;
        $limit = max(1, min($parallel, count($queue)));
        $next = 0;
        $queueSize = count($queue);
        $running = [];
        $failures = [];

        while ($next < $queueSize || $running !== []) {
            while ($next < $queueSize && count($running) < $limit) {
                $running[] = $this->startLintProcess($queue[$next++]);
            }

            foreach ($running as $key => $job) {
                $job['stdout'] .= stream_get_contents($job['pipes'][1]) ?: '';
                $job['stderr'] .= stream_get_contents($job['pipes'][2]) ?: '';
                $status = proc_get_status($job['process']);

                if ($status['running']) {
                    if ((microtime(true) - $job['started_at']) >= $timeout) {
                        proc_terminate($job['process']);
                        $job['stderr'] .= sprintf('PHP lint process timed out after %.2f seconds.', $timeout);
                    } else {
                        $running[$key] = $job;

                        continue;
                    }
                }

                $job['stdout'] .= stream_get_contents($job['pipes'][1]) ?: '';
                $job['stderr'] .= stream_get_contents($job['pipes'][2]) ?: '';

                if (is_resource($job['pipes'][1])) {
                    fclose($job['pipes'][1]);
                }

                if (is_resource($job['pipes'][2])) {
                    fclose($job['pipes'][2]);
                }

                $closeExitCode = proc_close($job['process']);
                $statusExitCode = $status['exitcode'];
                $exitCode = $statusExitCode !== -1 ? $statusExitCode : $closeExitCode;

                if ($exitCode !== 0 || str_contains($job['stderr'], 'timed out after')) {
                    $message = trim($job['stdout'] . PHP_EOL . $job['stderr']);
                    $failures[] = [
                        'file' => ProjectPath::relative($job['file']),
                        'message' => $message !== '' ? $message : 'Unknown lint failure',
                    ];
                }

                unset($running[$key]);
            }

            if ($running !== []) {
                usleep(1000);
            }
        }

        usort($failures, static fn(array $left, array $right): int => $left['file'] <=> $right['file']);

        return ['files_checked' => count($files), 'failures' => $failures];
    }

    /**
     * @param list<string> $files
     * @return array{files_checked:int,failures:list<array{file:string,message:string}>}
     */
    private function lintFilesSequential(array $files, float $timeout): array
    {
        $failures = [];

        foreach ($files as $file) {
            $failure = $this->lintFile($file, $timeout);

            if (is_string($failure)) {
                $failures[] = ['file' => ProjectPath::relative($file), 'message' => $failure];
            }
        }

        return ['files_checked' => count($files), 'failures' => $failures];
    }

    /**
     * @param SyntaxOptions $options
     * @return array{0:array{files_checked:int,failures:list<array{file:string,message:string}>},1:bool,2:int}
     */
    private function lintOutcome(array $options): array
    {
        $files = CheckerRuntime::phpFiles($options);
        $result = $files === []
            ? ['files_checked' => 0, 'failures' => []]
            : $this->lintFiles($files, $options['parallel'], $options['timeout']);
        $failed = $result['failures'] !== [];

        return [$result, $failed, $failed ? 1 : 0];
    }

    /**
     * @param list<string> $args
     * @return SyntaxOptions
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
            'timeout' => 30.0,
            'textColorSuccess' => 'green',
            'textColorError' => 'red',
            'textColorFile' => 'cyan',
            'config' => Paths::config('phpprobe.json'),
            'paths' => [],
            'excludes' => [],
        ];
        $options = $cli->resolvedConfig($args, $options)->applySyntaxOptions($options);
        $configuredPaths = OptionValues::strings($options, 'paths');
        $cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg, $cli),
            'Unknown option for syntax command: %s',
        );

        return $this->typedOptions($options);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $options
     */
    private function parseCliOption(array $args, int &$index, array &$options, string $arg, CliOptions $cli): bool
    {
        if ($cli->parseCommonCheckerOptions($args, $index, $options, $arg, false)) {
            return true;
        }

        $parallel = $cli->optionValue($arg, '--parallel');

        if ($parallel !== null) {
            if (filter_var($parallel, FILTER_VALIDATE_INT) === false || (int) $parallel < 1 || (int) $parallel > 64) {
                throw new \InvalidArgumentException('--parallel must be an integer between 1 and 64.');
            }

            $options['parallel'] = (int) $parallel;

            return true;
        }

        $timeout = $cli->optionValue($arg, '--timeout');

        if ($timeout !== null) {
            if (!is_numeric($timeout) || (float) $timeout < 0.1 || (float) $timeout > 600.0) {
                throw new \InvalidArgumentException('--timeout must be between 0.1 and 600 seconds.');
            }

            $options['timeout'] = (float) $timeout;

            return true;
        }

        return false;
    }

    /**
     * @param SyntaxOptions $options
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
     * @return array{file:string,process:resource,pipes:array{1:resource,2:resource},stdout:string,stderr:string,started_at:float}
     */
    private function startLintProcess(string $file): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('PHP function proc_open is required for syntax checking.');
        }

        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-l', $file], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Could not start syntax lint process for %s', $file));
        }

        fclose($pipes[0]);
        unset($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'file' => $file,
            'process' => $process,
            'pipes' => [1 => $pipes[1], 2 => $pipes[2]],
            'stdout' => '',
            'stderr' => '',
            'started_at' => microtime(true),
        ];
    }

    /**
     * @param array{files_checked:int,failures:list<array{file:string,message:string}>} $result
     * @param SyntaxOptions $options
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
     * @param array<string, mixed> $options
     * @return SyntaxOptions
     */
    private function typedOptions(array $options): array
    {
        /** @var SyntaxOptions $typed */
        $typed = OptionValues::coerce($options, [
            'help' => 'bool',
            'format' => 'string',
            'color' => 'string',
            'summaryJson' => 'string',
            'changedOnly' => 'bool',
            'changedBase' => 'string',
            'parallel' => 'int',
            'timeout' => 'float',
            'textColorSuccess' => 'string',
            'textColorError' => 'string',
            'textColorFile' => 'string',
            'config' => 'string',
            'paths' => 'strings',
            'excludes' => 'strings',
        ]);

        return $typed;
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
     * @param SyntaxOptions $options
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
     * @param SyntaxOptions $options
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
     * @param SyntaxOptions $options
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        if ($result['files_checked'] === 0) {
            fwrite(STDOUT, 'No PHP files found.' . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

            return;
        }

        if ($result['failures'] === []) {
            fwrite(STDOUT, Ansi::color(sprintf('Syntax OK: %d PHP files checked.', $result['files_checked']), $options['textColorSuccess'], STDOUT) . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

            return;
        }

        fwrite(STDERR, Ansi::color(sprintf('Syntax errors in %d file(s):', count($result['failures'])), $options['textColorError'], STDERR) . PHP_EOL);

        foreach ($result['failures'] as $failure) {
            fwrite(STDERR, '  ' . Ansi::color($failure['file'], $options['textColorFile'], STDERR) . PHP_EOL);

            foreach (preg_split('/\R/', trim($failure['message'])) ?: [] as $line) {
                if ($line !== '') {
                    fwrite(STDERR, '    ' . $line . PHP_EOL);
                }
            }
        }

        fwrite(STDERR, $this->summaryFooter($result, $options, $failed) . PHP_EOL);
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\OptionValues;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Console\CliTable;
use Infocyph\PHPProbe\Process\ProcessResult;
use Infocyph\PHPProbe\Process\ProcRunner;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\InputFileGroups;
use Infocyph\PHPProbe\Util\ProjectPath;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

/**
 * @phpstan-type SyntaxOptions array{help:bool,format:string,color:string,summaryJson:string,changedOnly:bool,changedBase:string,parallel:int,timeout:float,textColorSuccess:string,textColorError:string,textColorFile:string,config:string,paths:list<string>,excludes:list<string>}
 * @phpstan-type SyntaxFailure array{file:string,message:string}
 * @phpstan-type SyntaxGroup array{name:string,files_checked:int,failures:int,files:list<string>}
 * @phpstan-type SyntaxResult array{files_checked:int,failures:list<SyntaxFailure>,groups:list<SyntaxGroup>}
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

    /**
     * @param SyntaxFailure $failure
     * @return array{line:int,message:string}
     */
    private function diagnostic(array $failure): array
    {
        $line = preg_match('/\bon line\s+(\d+)\b/i', $failure['message'], $matches) === 1
            ? max(1, (int) $matches[1])
            : 1;
        $messages = [];

        foreach (preg_split('/\R/', trim($failure['message'])) ?: [] as $message) {
            $message = trim($message);

            if ($message === '' || preg_match('/^Errors parsing\s+/i', $message) === 1) {
                continue;
            }

            $message = preg_replace('/^(?:PHP\s+)?Parse error:\s*/i', '', $message) ?? $message;
            $message = preg_replace('/\s+in\s+.+\s+on line\s+\d+\s*$/i', '', $message) ?? $message;

            if ($message !== '' && !in_array($message, $messages, true)) {
                $messages[] = $message;
            }
        }

        return ['line' => $line, 'message' => $messages[0] ?? 'Unknown lint failure'];
    }

    /**
     * @param array<string, list<string>> $groups
     * @param list<SyntaxFailure> $failures
     * @return list<SyntaxGroup>
     */
    private function groupSummaries(array $groups, array $failures): array
    {
        $failedFiles = array_fill_keys(array_column($failures, 'file'), true);
        $summaries = [];

        foreach ($groups as $name => $files) {
            $relativeFiles = array_map(ProjectPath::relative(...), $files);
            $summaries[] = [
                'name' => $name,
                'files_checked' => count($files),
                'failures' => count(array_filter($relativeFiles, static fn(string $file): bool => isset($failedFiles[$file]))),
                'files' => $relativeFiles,
            ];
        }

        return $summaries;
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
            '  --format=text|json|phpstan-json|markdown|sarif|github output format (default: text)',
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
     * @return array{0:SyntaxResult,1:bool,2:int}
     */
    private function lintOutcome(array $options): array
    {
        $files = CheckerRuntime::phpFiles($options);
        $groups = InputFileGroups::group($files, $options['paths']);
        $queue = InputFileGroups::roundRobin($groups);
        $result = $files === []
            ? ['files_checked' => 0, 'failures' => []]
            : $this->lintFiles($queue, $options['parallel'], $options['timeout']);
        $result['groups'] = $this->groupSummaries($groups, $result['failures']);
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
        if ($cli->parseCommonCheckerOptions(
            $args,
            $index,
            $options,
            $arg,
            false,
            ['text', 'json', 'phpstan-json', 'markdown', 'sarif', 'github'],
        )) {
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
     * @param SyntaxResult $result
     * @param SyntaxOptions $options
     */
    private function summaryFooter(array $result, array $options, bool $failed): string
    {
        return sprintf(
            'Summary: files=%d failures=%d groups=%d parallel=%d status=%s',
            $result['files_checked'],
            count($result['failures']),
            count($result['groups']),
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
     * @param SyntaxResult $result
     */
    private function writeGithub(array $result): void
    {
        foreach ($result['failures'] as $failure) {
            $diagnostic = $this->diagnostic($failure);
            fwrite(STDOUT, GithubAnnotation::emit(
                'error',
                'PHPProbe syntax',
                $diagnostic['message'],
                $failure['file'],
                $diagnostic['line'],
            ) . PHP_EOL);
        }

        if ($result['failures'] === []) {
            fwrite(STDOUT, GithubAnnotation::emit('notice', 'PHPProbe syntax', 'No syntax errors found.') . PHP_EOL);
        }
    }

    /**
     * @param SyntaxResult $result
     */
    private function writeJson(array $result): void
    {
        fwrite(STDOUT, json_encode([
            'files_checked' => $result['files_checked'],
            'failures' => $result['failures'],
            'groups' => array_map(static fn(array $group): array => [
                'name' => $group['name'],
                'files_checked' => $group['files_checked'],
                'failures' => $group['failures'],
            ], $result['groups']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param SyntaxResult $result
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
            $lines[] = '| File | Line | Message |';
            $lines[] = '| --- | ---: | --- |';

            foreach ($result['failures'] as $failure) {
                $diagnostic = $this->diagnostic($failure);
                $lines[] = sprintf(
                    '| `%s` | %d | %s |',
                    $failure['file'],
                    $diagnostic['line'],
                    str_replace('|', '\|', $diagnostic['message']),
                );
            }
        }

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param SyntaxResult $result
     */
    private function writePhpStanJson(array $result): void
    {
        /** @var array<string, array{errors:int,messages:list<array{message:string,line:int,ignorable:bool,identifier:string}>}> $files */
        $files = [];

        foreach ($result['failures'] as $failure) {
            $diagnostic = $this->diagnostic($failure);
            $files[$failure['file']] ??= ['errors' => 0, 'messages' => []];
            $files[$failure['file']]['errors']++;
            $files[$failure['file']]['messages'][] = [
                'message' => $diagnostic['message'],
                'line' => $diagnostic['line'],
                'ignorable' => false,
                'identifier' => 'php.syntax',
            ];
        }

        fwrite(STDOUT, json_encode([
            'totals' => ['errors' => 0, 'file_errors' => count($result['failures'])],
            'files' => (object) $files,
            'errors' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param SyntaxResult $result
     * @param SyntaxOptions $options
     */
    private function writeResult(array $result, array $options, bool $failed): void
    {
        match ($options['format']) {
            'json' => $this->writeJson($result),
            'phpstan-json' => $this->writePhpStanJson($result),
            'markdown' => $this->writeMarkdown($result, $failed),
            'sarif' => $this->writeSarif($result),
            'github' => $this->writeGithub($result),
            default => $this->writeText($result, $options, $failed),
        };
    }

    /**
     * @param SyntaxResult $result
     */
    private function writeSarif(array $result): void
    {
        $results = [];

        foreach ($result['failures'] as $failure) {
            $diagnostic = $this->diagnostic($failure);
            $results[] = [
                'ruleId' => 'php_syntax_error',
                'level' => 'error',
                'message' => ['text' => $diagnostic['message']],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $failure['file']],
                        'region' => ['startLine' => $diagnostic['line']],
                    ],
                ]],
            ];
        }

        fwrite(STDOUT, json_encode(Sarif::payload($results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param SyntaxResult $result
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
            'groups' => count($result['groups']),
            'parallel' => $options['parallel'],
        ]);
    }

    /**
     * @param SyntaxResult $result
     * @param SyntaxOptions $options
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        $stream = $failed ? STDERR : STDOUT;
        fwrite($stream, Ansi::color('PHPProbe Syntax', $failed ? $options['textColorError'] : $options['textColorSuccess'], $stream) . PHP_EOL);
        fwrite($stream, CliTable::render(
            ['Group', 'Files', 'Errors'],
            array_map(static fn(array $group): array => [
                $group['name'],
                $group['files_checked'],
                $group['failures'],
            ], $result['groups']),
            [0 => 40],
        ) . PHP_EOL);

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
        $rows = [];

        foreach ($result['failures'] as $failure) {
            $diagnostic = $this->diagnostic($failure);
            $rows[] = [
                InputFileGroups::nameFor($failure['file'], array_column($result['groups'], 'files', 'name')),
                $failure['file'],
                $diagnostic['line'],
                $diagnostic['message'],
            ];
        }

        fwrite(STDERR, CliTable::render(
            ['Group', 'File', 'Line', 'Message'],
            $rows,
            [0 => 24, 1 => 48, 2 => 6, 3 => 80],
        ) . PHP_EOL);

        fwrite(STDERR, $this->summaryFooter($result, $options, $failed) . PHP_EOL);
    }
}

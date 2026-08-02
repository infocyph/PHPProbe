<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

use Infocyph\PHPProbe\Process\ProcRunner;
use Infocyph\PHPProbe\Util\AtomicFileWriter;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

/**
 * @phpstan-type CheckOptions array{config:string,preset:string,format:string,summaryJson:string,reportDir:string,changedOnly:bool,changedBase:string,parallel:string,timeout:string,failOn:string,excludes:list<string>,duplicateArgs:list<string>,paths:list<string>,help:bool}
 */
final class CheckCommand
{
    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        try {
            $options = $this->parseArgs($args);

            if ($options['help']) {
                return $this->help();
            }

            $results = ['syntax' => $this->runChecker('syntax', $options)];

            if ($results['syntax']['exit_code'] === 0) {
                $results['duplicates'] = $this->runChecker('duplicates', $options);
            }

            $exitCode = $this->combinedExitCode($results);
            $summary = $this->summaryPayload($results, $exitCode);
            SummaryJson::writeIfConfigured($options['summaryJson'], $summary);

            if ($options['reportDir'] !== '') {
                $this->writeReportArtifacts($options['reportDir'], $results, $summary);
            }

            $this->writeOutput($options['format'], $results, $summary);

            return $exitCode;
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }
    }

    /**
     * @param CheckOptions $options
     * @return list<string>
     */
    private function checkerArgs(string $checker, array $options): array
    {
        $args = ['--format=json', '--color=never'];

        foreach (['config', 'preset', 'changedBase'] as $key) {
            if ($options[$key] !== '') {
                $name = match ($key) {
                    'changedBase' => '--changed-base=',
                    default => '--' . $key . '=',
                };
                $args[] = $name . $options[$key];
            }
        }

        if ($options['changedOnly']) {
            $args[] = '--changed-only';
        }

        foreach ($options['excludes'] as $exclude) {
            $args[] = '--exclude=' . $exclude;
        }

        if ($checker === 'syntax') {
            if ($options['parallel'] !== '') {
                $args[] = '--parallel=' . $options['parallel'];
            }

            if ($options['timeout'] !== '') {
                $args[] = '--timeout=' . $options['timeout'];
            }
        } else {
            if ($options['failOn'] !== '') {
                $args[] = '--fail-on=' . $options['failOn'];
            }

            $args = [...$args, ...$options['duplicateArgs']];
        }

        return [...$args, ...$options['paths']];
    }

    /**
     * @param array<string, array{exit_code:int,stderr:string,payload:array<string,mixed>}> $results
     */
    private function combinedExitCode(array $results): int
    {
        $exitCode = 0;

        foreach ($results as $result) {
            if ($result['exit_code'] === 2) {
                return 2;
            }

            if ($result['exit_code'] !== 0) {
                $exitCode = 1;
            }
        }

        return $exitCode;
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe check [options] [paths...]',
            '',
            'Runs syntax first, then duplicate analysis only when syntax passes.',
            '',
            'Options:',
            '  --config=FILE                    read PHPProbe checker settings',
            '  --preset=NAME                    apply preset: default, standard, ci, or strict',
            '  --format=text|json|markdown|sarif|github output format (default: text)',
            '  --summary-json=FILE              write combined summary JSON',
            '  --report-dir=DIR                 write JSON, Markdown, and SARIF reports',
            '  --changed-only                   scan only changed PHP files from Git diff',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --parallel=N                     pass worker count to syntax checker',
            '  --timeout=SECONDS                pass process timeout to syntax checker',
            '  --fail-on=error|warning|info     pass threshold to duplicate checker',
            '  --exclude=PATH                   exclude a path from both checkers; repeatable',
            '  --mode=gate|audit                select duplicate detector mode',
            '  --exact | --fuzzy | --no-fuzzy  select token normalization',
            '  --near-miss                      enable bounded AST-shape comparison',
            '  Duplicate threshold, baseline, and cache options are also forwarded.',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @param array{checker:string,exit_code:int,checks:array<string,int>,skipped:list<string>} $summary
     */
    private function markdown(array $summary): string
    {
        $lines = ['# PHPProbe Check Report', '', '| Checker | Result |', '| --- | --- |'];

        foreach ($summary['checks'] as $name => $exitCode) {
            $lines[] = sprintf('| `%s` | `%s` |', $name, $exitCode === 0 ? 'PASS' : 'FAIL');
        }

        foreach ($summary['skipped'] as $name) {
            $lines[] = sprintf('| `%s` | `SKIPPED` |', $name);
        }

        $lines[] = '';
        $lines[] = sprintf('Overall exit code: `%d`', $summary['exit_code']);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<string> $args
     * @return CheckOptions
     */
    private function parseArgs(array $args): array
    {
        $options = [
            'config' => '',
            'preset' => '',
            'format' => 'text',
            'summaryJson' => '',
            'reportDir' => '',
            'changedOnly' => false,
            'changedBase' => '',
            'parallel' => '',
            'timeout' => '',
            'failOn' => '',
            'excludes' => [],
            'duplicateArgs' => [],
            'paths' => [],
            'help' => false,
        ];

        for ($index = 0, $count = count($args); $index < $count; $index++) {
            $arg = $args[$index];

            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;

                continue;
            }

            if ($arg === '--changed-only') {
                $options['changedOnly'] = true;

                continue;
            }

            if (in_array($arg, ['--near-miss', '--exact', '--fuzzy', '--no-fuzzy', '--no-cache', '--write-baseline'], true)) {
                $options['duplicateArgs'][] = $arg;

                continue;
            }

            $matched = false;

            foreach ([
                '--config' => 'config',
                '--preset' => 'preset',
                '--summary-json' => 'summaryJson',
                '--report-dir' => 'reportDir',
                '--changed-base' => 'changedBase',
                '--parallel' => 'parallel',
                '--timeout' => 'timeout',
                '--fail-on' => 'failOn',
            ] as $name => $key) {
                $value = $this->valuedArgument($args, $index, $arg, $name);

                if ($value !== null) {
                    $options[$key] = $value;
                    $matched = true;

                    break;
                }
            }

            if ($matched) {
                continue;
            }

            $format = $this->valuedArgument($args, $index, $arg, '--format');

            if ($format !== null) {
                $format = strtolower($format);

                if (!in_array($format, ['text', 'json', 'markdown', 'sarif', 'github'], true)) {
                    throw new \InvalidArgumentException('--format must be one of: text, json, markdown, sarif, github.');
                }

                $options['format'] = $format;

                continue;
            }

            $exclude = $this->valuedArgument($args, $index, $arg, '--exclude');

            if ($exclude !== null) {
                $options['excludes'][] = $exclude;

                continue;
            }

            foreach ([
                '--mode',
                '--min-lines',
                '--min-tokens',
                '--min-statements',
                '--min-similarity',
                '--max-near-miss-comparisons',
                '--baseline',
                '--write-baseline',
                '--cache-file',
                '--error-duplicate-percentage',
            ] as $name) {
                $value = $this->valuedArgument($args, $index, $arg, $name);

                if ($value !== null) {
                    $options['duplicateArgs'][] = $name . '=' . $value;
                    $matched = true;

                    break;
                }
            }

            if ($matched) {
                continue;
            }

            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException(sprintf('Unknown option for check command: %s', $arg));
            }

            $options['paths'][] = $arg;
        }

        if ($options['failOn'] !== '' && !in_array($options['failOn'], ['error', 'warning', 'info'], true)) {
            throw new \InvalidArgumentException('--fail-on must be one of: error, warning, info.');
        }

        return $options;
    }

    /**
     * @param CheckOptions $options
     * @return array{exit_code:int,stderr:string,payload:array<string,mixed>}
     */
    private function runChecker(string $checker, array $options): array
    {
        $binary = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpprobe';
        $run = (new ProcRunner())->run(
            [PHP_BINARY, $binary, $checker, ...$this->checkerArgs($checker, $options)],
            cwd: getcwd() ?: null,
            timeout: 900.0,
            outputLimit: 67_108_864,
        );

        if ($run === null) {
            throw new \RuntimeException(sprintf('Could not start "%s" checker process.', $checker));
        }

        try {
            $payload = json_decode($run->stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf(
                'The %s checker returned invalid JSON%s%s',
                $checker,
                $run->stderr === '' ? '.' : ': ',
                trim($run->stderr),
            ), previous: $exception);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException(sprintf('The %s checker returned an invalid result object.', $checker));
        }

        /** @var array<string, mixed> $payload */
        return ['exit_code' => $run->exitCode, 'stderr' => $run->stderr, 'payload' => $payload];
    }

    /**
     * @param array<string, array{exit_code:int,stderr:string,payload:array<string,mixed>}> $results
     * @return array<string, mixed>
     */
    private function sarif(array $results): array
    {
        $findings = [];
        $syntax = $results['syntax']['payload']['failures'] ?? [];

        if (is_array($syntax)) {
            foreach ($syntax as $failure) {
                if (!is_array($failure)) {
                    continue;
                }

                $message = is_string($failure['message'] ?? null) ? $failure['message'] : 'Syntax error';
                $file = is_string($failure['file'] ?? null) ? $failure['file'] : '';
                $findings[] = $this->sarifFinding('php_syntax_error', 'error', $message, $file, 1);
            }
        }

        $clones = $results['duplicates']['payload']['clones'] ?? [];

        if (is_array($clones)) {
            foreach ($clones as $clone) {
                if (!is_array($clone) || !is_array($clone['occurrences'] ?? null)) {
                    continue;
                }

                foreach ($clone['occurrences'] as $occurrence) {
                    if (is_array($occurrence)) {
                        $source = is_string($clone['source'] ?? null) ? $clone['source'] : 'tokens';
                        $file = is_string($occurrence['file'] ?? null) ? $occurrence['file'] : '';
                        $line = is_int($occurrence['start_line'] ?? null) ? $occurrence['start_line'] : 1;
                        $findings[] = $this->sarifFinding(
                            'duplicate_code_clone',
                            'warning',
                            sprintf('Duplicate clone group (%s).', $source),
                            $file,
                            $line,
                        );
                    }
                }
            }
        }

        return Sarif::payload($findings);
    }

    /** @return array<string, mixed> */
    private function sarifFinding(string $rule, string $level, string $message, string $file, int $line): array
    {
        return [
            'ruleId' => $rule,
            'level' => $level,
            'message' => ['text' => trim($message)],
            'locations' => [[
                'physicalLocation' => [
                    'artifactLocation' => ['uri' => $file],
                    'region' => ['startLine' => max(1, $line)],
                ],
            ]],
        ];
    }

    /**
     * @param array<string, array{exit_code:int,stderr:string,payload:array<string,mixed>}> $results
     * @return array{checker:string,exit_code:int,checks:array<string,int>,skipped:list<string>}
     */
    private function summaryPayload(array $results, int $exitCode): array
    {
        $checks = [];

        foreach ($results as $name => $result) {
            $checks[$name] = $result['exit_code'];
        }

        return [
            'checker' => 'check',
            'exit_code' => $exitCode,
            'checks' => $checks,
            'skipped' => isset($results['duplicates']) ? [] : ['duplicates'],
        ];
    }

    /**
     * @param list<string> $args
     */
    private function valuedArgument(array $args, int &$index, string $arg, string $name): ?string
    {
        if (str_starts_with($arg, $name . '=')) {
            $value = trim(substr($arg, strlen($name) + 1));
        } elseif ($arg === $name) {
            $index++;
            $value = trim($args[$index] ?? '');
        } else {
            return null;
        }

        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('%s requires a value.', $name));
        }

        return $value;
    }

    /**
     * @param array<string, array{exit_code:int,stderr:string,payload:array<string,mixed>}> $results
     */
    private function writeGithub(array $results): void
    {
        foreach ($results as $name => $result) {
            $level = $result['exit_code'] === 0 ? 'notice' : 'error';
            $message = $result['exit_code'] === 0 ? 'PASS' : (trim($result['stderr']) ?: 'FAILED');
            fwrite(STDOUT, GithubAnnotation::emit($level, 'PHPProbe ' . $name, $message) . PHP_EOL);
        }
    }

    /**
     * @param array<string, array{exit_code:int,stderr:string,payload:array<string,mixed>}> $results
     * @param array{checker:string,exit_code:int,checks:array<string,int>,skipped:list<string>} $summary
     */
    private function writeOutput(string $format, array $results, array $summary): void
    {
        if ($format === 'json') {
            fwrite(STDOUT, json_encode(['summary' => $summary, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return;
        }

        if ($format === 'sarif') {
            fwrite(STDOUT, json_encode($this->sarif($results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return;
        }

        if ($format === 'github') {
            $this->writeGithub($results);

            return;
        }

        if ($format === 'markdown') {
            fwrite(STDOUT, $this->markdown($summary));

            return;
        }

        fwrite(STDOUT, 'PHPProbe check summary:' . PHP_EOL);

        foreach ($summary['checks'] as $name => $exitCode) {
            fwrite(STDOUT, sprintf('  - %s: exit=%d', $name, $exitCode) . PHP_EOL);
        }

        foreach ($summary['skipped'] as $name) {
            fwrite(STDOUT, sprintf('  - %s: skipped', $name) . PHP_EOL);
        }

        fwrite(STDOUT, sprintf('Overall exit: %d', $summary['exit_code']) . PHP_EOL);
    }

    /**
     * @param array<string, array{exit_code:int,stderr:string,payload:array<string,mixed>}> $results
     * @param array{checker:string,exit_code:int,checks:array<string,int>,skipped:list<string>} $summary
     */
    private function writeReportArtifacts(string $directory, array $results, array $summary): void
    {
        foreach ($results as $name => $result) {
            AtomicFileWriter::write(
                $directory . DIRECTORY_SEPARATOR . $name . '.json',
                json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            );
        }

        AtomicFileWriter::write($directory . DIRECTORY_SEPARATOR . 'summary.md', $this->markdown($summary));
        AtomicFileWriter::write(
            $directory . DIRECTORY_SEPARATOR . 'report.sarif',
            json_encode($this->sarif($results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
        SummaryJson::write($directory . DIRECTORY_SEPARATOR . 'summary.json', $summary);
    }
}

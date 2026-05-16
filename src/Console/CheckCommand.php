<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

use Infocyph\PHPProbe\Process\ProcRunner;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\SummaryJson;

final class CheckCommand
{
    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        try {
            $options = $this->parseArgs($args);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }

        if ($options['help']) {
            return $this->help();
        }

        $checkers = ['syntax', 'duplicates', 'api', 'comments'];
        $results = [];

        foreach ($checkers as $checker) {
            $results[$checker] = $this->runChecker($checker, $options, 'json');
        }

        $exitCode = $this->combinedExitCode($results);
        $summary = $this->summaryPayload($results, $exitCode);

        if ($options['summaryJson'] !== '') {
            $this->ensureParentDirectory($options['summaryJson']);
            SummaryJson::write($options['summaryJson'], $summary);
        }

        if ($options['reportDir'] !== '') {
            $this->writeReportArtifacts($checkers, $options, $results, $summary);
        }

        $this->writeOutput($options, $results, $summary);

        return $exitCode;
    }

    /**
     * @return array{config:string,preset:string,format:string,summaryJson:string,reportDir:string,changedOnly:bool,changedBase:string,failOn:string,failConfidence:string,docMode:string,explain:bool,paths:list<string>,help:bool}
     */
    private function baseOutputOptions(): array
    {
        return [
            'config' => '',
            'preset' => '',
            'format' => 'text',
            'color' => 'auto',
            'summaryJson' => '',
            'reportDir' => '',
            'changedOnly' => false,
            'changedBase' => '',
            'failOn' => '',
            'failConfidence' => '',
            'docMode' => '',
            'explain' => false,
            'paths' => [],
            'help' => false,
        ];
    }

    /**
     * @param array{config:string,preset:string,format:string,color:string,summaryJson:string,reportDir:string,changedOnly:bool,changedBase:string,failOn:string,paths:list<string>,help:bool} $options
     * @return list<string>
     */
    private function checkerArgs(string $checker, array $options, string $format): array
    {
        $args = ['--format=' . $format];
        $args[] = '--color=' . $options['color'];

        if ($options['config'] !== '') {
            $args[] = '--config=' . $options['config'];
        }

        if ($options['preset'] !== '') {
            $args[] = '--preset=' . $options['preset'];
        }

        if ($options['changedOnly']) {
            $args[] = '--changed-only';
        }

        if ($options['changedBase'] !== '') {
            $args[] = '--changed-base=' . $options['changedBase'];
        }

        if ($options['failOn'] !== '' && in_array($checker, ['duplicates', 'api', 'comments'], true)) {
            $args[] = '--fail-on=' . $options['failOn'];
        }

        if ($checker === 'comments') {
            if ($options['failConfidence'] !== '') {
                $args[] = '--fail-confidence=' . $options['failConfidence'];
            }

            if ($options['docMode'] !== '') {
                $args[] = '--doc-mode=' . $options['docMode'];
            }

            if ($options['explain']) {
                $args[] = '--explain';
            }
        }

        return [...$args, ...$options['paths']];
    }

    /**
     * @param array<string, array{exit_code:int,stdout:string,stderr:string,payload:array<string,mixed>}> $results
     */
    private function combinedExitCode(array $results): int
    {
        $hasFailure = false;

        foreach ($results as $result) {
            if ($result['exit_code'] === 2) {
                return 2;
            }

            if ($result['exit_code'] !== 0) {
                $hasFailure = true;
            }
        }

        return $hasFailure ? 1 : 0;
    }

    private function ensureParentDirectory(string $path): void
    {
        $directory = dirname($path);

        if ($directory === '' || $directory === '.') {
            return;
        }

        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create directory: %s', $directory));
        }
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe check [options] [paths...]',
            '',
            'Options:',
            '  --config=FILE                    read PHPProbe checker settings',
            '  --preset=NAME                    apply preset: default, standard, ci, or strict',
            '  --format=text|json|markdown|sarif|github',
            '  --color=auto|always|never       ANSI color mode for checker output (default: auto)',
            '  --summary-json=FILE              write combined summary JSON',
            '  --report-dir=DIR                 write per-checker text/json/markdown/sarif artifacts',
            '  --changed-only                   scan only changed PHP files from Git diff',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --fail-on=error|warning|info     pass through to duplicates/api/comments',
            '  --fail-confidence=low|medium|high pass through to comments',
            '  --doc-mode=heuristic|parser|hybrid pass through to comments',
            '  --explain                        pass through to comments',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @param array{exit_code:int,stdout:string,stderr:string,payload:array<string,mixed>} $run
     */
    private function outputForReport(array $run, string $format): string
    {
        if ($format === 'text') {
            $stream = trim($run['stderr']) !== '' ? $run['stderr'] : $run['stdout'];

            return rtrim($stream) . PHP_EOL;
        }

        return rtrim($run['stdout']) . PHP_EOL;
    }

    /**
     * @param list<string> $args
     * @return array{config:string,preset:string,format:string,color:string,summaryJson:string,reportDir:string,changedOnly:bool,changedBase:string,failOn:string,failConfidence:string,docMode:string,explain:bool,paths:list<string>,help:bool}
     */
    private function parseArgs(array $args): array
    {
        $options = $this->baseOutputOptions();
        $count = count($args);

        for ($index = 0; $index < $count; $index++) {
            $arg = $args[$index];

            if ($arg === '--') {
                $options['paths'] = [...$options['paths'], ...array_slice($args, $index + 1)];

                break;
            }

            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;

                continue;
            }

            if (str_starts_with($arg, '--config=')) {
                $options['config'] = substr($arg, strlen('--config='));

                continue;
            }

            if ($arg === '--config' && isset($args[$index + 1])) {
                $options['config'] = $args[++$index];

                continue;
            }

            if (str_starts_with($arg, '--preset=')) {
                $options['preset'] = substr($arg, strlen('--preset='));

                continue;
            }

            if ($arg === '--preset' && isset($args[$index + 1])) {
                $options['preset'] = $args[++$index];

                continue;
            }

            if (str_starts_with($arg, '--format=')) {
                $format = strtolower(trim(substr($arg, strlen('--format='))));

                if (!in_array($format, ['text', 'json', 'markdown', 'sarif', 'github'], true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Invalid --format value "%s". Expected one of: text, json, markdown, sarif, github.',
                        $format,
                    ));
                }

                $options['format'] = $format;

                continue;
            }

            if (str_starts_with($arg, '--color=')) {
                $color = strtolower(trim(substr($arg, strlen('--color='))));

                if (!in_array($color, ['auto', 'always', 'never'], true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Invalid --color value "%s". Expected: auto, always, never.',
                        $color,
                    ));
                }

                $options['color'] = $color;

                continue;
            }

            if (str_starts_with($arg, '--summary-json=')) {
                $options['summaryJson'] = trim(substr($arg, strlen('--summary-json=')));

                continue;
            }

            if (str_starts_with($arg, '--report-dir=')) {
                $options['reportDir'] = trim(substr($arg, strlen('--report-dir=')));

                continue;
            }

            if ($arg === '--changed-only') {
                $options['changedOnly'] = true;

                continue;
            }

            if (str_starts_with($arg, '--changed-base=')) {
                $options['changedBase'] = trim(substr($arg, strlen('--changed-base=')));

                continue;
            }

            if (str_starts_with($arg, '--fail-on=')) {
                $options['failOn'] = strtolower(trim(substr($arg, strlen('--fail-on='))));

                continue;
            }

            $failConfidence = $this->parseEnumOptionFromArg(
                $arg,
                '--fail-confidence=',
                ['low', 'medium', 'high'],
                'Invalid --fail-confidence value "%s". Expected: low, medium, high.',
            );

            if ($failConfidence !== null) {
                $options['failConfidence'] = $failConfidence;

                continue;
            }

            $docMode = $this->parseEnumOptionFromArg(
                $arg,
                '--doc-mode=',
                ['heuristic', 'parser', 'hybrid'],
                'Invalid --doc-mode value "%s". Expected: heuristic, parser, hybrid.',
            );

            if ($docMode !== null) {
                $options['docMode'] = $docMode;

                continue;
            }

            if ($arg === '--explain') {
                $options['explain'] = true;

                continue;
            }

            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException(sprintf('Unknown option for check command: %s', $arg));
            }

            $options['paths'][] = $arg;
        }

        return $options;
    }

    /**
     * @param list<string> $allowed
     */
    private function parseEnumOptionFromArg(
        string $arg,
        string $prefix,
        array $allowed,
        string $errorMessage,
    ): ?string {
        if (!str_starts_with($arg, $prefix)) {
            return null;
        }

        $value = strtolower(trim(substr($arg, strlen($prefix))));

        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf($errorMessage, $value));
        }

        return $value;
    }

    /**
     * @param array{config:string,preset:string,format:string,color:string,summaryJson:string,reportDir:string,changedOnly:bool,changedBase:string,failOn:string,failConfidence:string,docMode:string,explain:bool,paths:list<string>,help:bool} $options
     * @return array{exit_code:int,stdout:string,stderr:string,payload:array<string,mixed>}
     */
    private function runChecker(string $checker, array $options, string $format): array
    {
        $args = $this->checkerArgs($checker, $options, $format);
        $binary = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpprobe';
        $run = (new ProcRunner())->run([PHP_BINARY, $binary, $checker, ...$args], '', getcwd() ?: null);

        if ($run === null) {
            throw new \RuntimeException(sprintf('Could not start "%s" checker process.', $checker));
        }

        $stdout = $run->stdout;
        $stderr = $run->stderr;
        $exitCode = $run->exitCode;
        $payload = json_decode($stdout, true);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * @param array<string, array{exit_code:int,stdout:string,stderr:string,payload:array<string,mixed>}> $results
     * @return array{checker:string,exit_code:int,checks:array<string,int>}
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
        ];
    }

    /**
     * @param array<string, array{exit_code:int,stdout:string,stderr:string,payload:array<string,mixed>}> $results
     */
    private function writeOutput(array $options, array $results, array $summary): void
    {
        $format = $options['format'];

        if ($format === 'json') {
            fwrite(STDOUT, json_encode([
                'summary' => $summary,
                'checks' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return;
        }

        if ($format === 'markdown') {
            $lines = [
                '# PHPProbe Check Report',
                '',
                sprintf('- Exit code: `%d`', $summary['exit_code']),
                '',
                '| Checker | Exit |',
                '| --- | --- |',
            ];

            foreach ($results as $name => $result) {
                $lines[] = sprintf('| `%s` | `%d` |', $name, $result['exit_code']);
            }

            fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);

            return;
        }

        if ($format === 'sarif') {
            $runs = [];

            foreach ($results as $name => $_result) {
                $sarif = $this->runChecker($name, $options, 'sarif');
                $payload = json_decode($sarif['stdout'], true);

                if (is_array($payload) && is_array($payload['runs'] ?? null)) {
                    $runs = [...$runs, ...$payload['runs']];
                }
            }

            fwrite(STDOUT, json_encode([
                'version' => '2.1.0',
                '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
                'runs' => $runs,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return;
        }

        if ($format === 'github') {
            foreach ($results as $name => $result) {
                if ($result['exit_code'] === 0) {
                    fwrite(STDOUT, GithubAnnotation::emit('notice', 'PHPProbe ' . $name, 'PASS') . PHP_EOL);

                    continue;
                }

                $message = trim($result['stderr']) !== '' ? trim($result['stderr']) : ('Checker failed: ' . $name);
                fwrite(STDOUT, GithubAnnotation::emit('error', 'PHPProbe ' . $name, $message) . PHP_EOL);
            }

            return;
        }

        fwrite(STDOUT, 'PHPProbe check summary:' . PHP_EOL);

        foreach ($results as $name => $result) {
            fwrite(STDOUT, sprintf('  - %s: exit=%d', $name, $result['exit_code']) . PHP_EOL);
        }

        fwrite(STDOUT, sprintf('Overall exit: %d', $summary['exit_code']) . PHP_EOL);
    }

    /**
     * @param array{config:string,preset:string,format:string,color:string,summaryJson:string,reportDir:string,changedOnly:bool,changedBase:string,failOn:string,failConfidence:string,docMode:string,explain:bool,paths:list<string>,help:bool} $options
     * @param array<string, array{exit_code:int,stdout:string,stderr:string,payload:array<string,mixed>}> $results
     */
    private function writeReportArtifacts(array $checkers, array $options, array $results, array $summary): void
    {
        if (!is_dir($options['reportDir']) && !mkdir($options['reportDir'], 0755, true) && !is_dir($options['reportDir'])) {
            throw new \RuntimeException(sprintf('Failed to create report directory: %s', $options['reportDir']));
        }

        foreach ($checkers as $checker) {
            foreach (['text', 'json', 'markdown', 'sarif'] as $format) {
                $run = $format === 'json' ? $results[$checker] : $this->runChecker($checker, $options, $format);
                $extension = match ($format) {
                    'markdown' => 'md',
                    default => $format,
                };
                file_put_contents(
                    $options['reportDir'] . DIRECTORY_SEPARATOR . $checker . '.' . $extension,
                    $this->outputForReport($run, $format),
                );
            }
        }

        SummaryJson::write($options['reportDir'] . DIRECTORY_SEPARATOR . 'summary.json', $summary);
    }
}

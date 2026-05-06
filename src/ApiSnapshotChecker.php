<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Api\ApiSnapshotIndex;
use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Filesystem\PhpFileFinder;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

final class ApiSnapshotChecker
{
    private CliOptions $cli;

    public function __construct()
    {
        $this->cli = new CliOptions();
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        try {
            return $this->runWithOptions($this->parseArgs($args));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runWithOptions(array $options): int
    {
        if ($options['help']) {
            return $this->help();
        }

        $files = (new PhpFileFinder())->find(
            $options['paths'],
            $options['excludes'],
            ['changedOnly' => $options['changedOnly'], 'changedBase' => $options['changedBase']],
        );
        $snapshot = (new ApiSnapshotIndex())->build($files, ['includeProtected' => $options['includeProtected']]);
        $result = $this->result($snapshot, $options['baseline']);

        if ($options['writeBaseline'] !== '') {
            $this->writeBaseline($snapshot, $options['writeBaseline']);
        }

        $failed = $this->shouldFail($result, $options);
        $exitCode = $options['writeBaseline'] !== '' ? 0 : ($failed ? 1 : 0);
        $this->writeResult($result, $options, $failed);
        $this->writeSummaryJson($result, $options, $exitCode);

        return $exitCode;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOptions(): array
    {
        return [
            'help' => false,
            'format' => 'text',
            'failOn' => 'warning',
            'summaryJson' => '',
            'changedOnly' => false,
            'changedBase' => '',
            'config' => Paths::config('phpprobe.json'),
            'preset' => '',
            'includeProtected' => true,
            'baseline' => '',
            'writeBaseline' => '',
            'paths' => [],
            'excludes' => [],
        ];
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe api [options] [paths...]',
            '',
            'Options:',
            '  --config=FILE                    read PHPProbe checker settings',
            '  --preset=NAME                    apply preset: phpstorm, standard, or strict',
            '  --exclude=PATH                   skip a path (repeatable)',
            '  --public-only                    ignore protected members',
            '  --include-protected              include protected members (default)',
            '  --baseline=FILE                  compare against a public API snapshot',
            '  --write-baseline[=FILE]          write the current public API snapshot and exit 0',
            '  --format=text|json|markdown|sarif output format (default: text)',
            '  --json                           alias for --format=json',
            '  --fail-on=error|warning|info     failure threshold (default: warning)',
            '  --summary-json=FILE              write machine-readable run summary',
            '  --changed-only                   scan only changed PHP files from Git diff',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function keyedSymbols(array $snapshot): array
    {
        $symbols = [];
        $items = is_array($snapshot['symbols'] ?? null) ? $snapshot['symbols'] : [];

        foreach ($items as $symbol) {
            if (is_array($symbol) && is_string($symbol['id'] ?? null)) {
                $symbols[$symbol['id']] = $symbol;
            }
        }

        return $symbols;
    }

    /**
     * @return array{version:int,generated_at:string,symbols:list<array<string, mixed>>}
     */
    private function loadBaseline(string $path): array
    {
        if ($path === '') {
            return ['version' => 1, 'generated_at' => '', 'symbols' => []];
        }

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('API baseline file not found: %s', $path));
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('API baseline file is not readable: %s', $path));
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Failed to read API baseline file: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Invalid API baseline JSON at %s: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('API baseline payload must be a JSON object: %s', $path));
        }

        $symbols = is_array($decoded['symbols'] ?? null) ? array_values(array_filter(
            $decoded['symbols'],
            static fn(mixed $symbol): bool => is_array($symbol),
        )) : [];

        return [
            'version' => is_int($decoded['version'] ?? null) ? $decoded['version'] : 1,
            'generated_at' => is_string($decoded['generated_at'] ?? null) ? $decoded['generated_at'] : '',
            'symbols' => $symbols,
        ];
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function parseArgs(array $args): array
    {
        $options = $this->defaultOptions();
        $options['config'] = $this->cli->configPath($args, $options['config']);
        $config = PhpProbeConfig::fromFile($options['config']);
        $options = $this->cli->mergeConfigWithPreset($config, $this->cli->presetName($args))->applyApiOptions($options);
        $configuredPaths = $options['paths'];
        $this->cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg),
            'Unknown option for api command: %s',
        );

        return $options;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $options
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

        if ($this->cli->parseOutputFormat($options, $arg)) {
            return true;
        }

        if ($this->cli->parseFailOn($options, $arg)) {
            return true;
        }

        if ($this->cli->parseSummaryJson($options, $arg)) {
            return true;
        }

        if ($this->cli->parseChangedOptions($options, $arg)) {
            return true;
        }

        if ($arg === '--public-only' || $arg === '--include-protected') {
            $options['includeProtected'] = $arg === '--include-protected';

            return true;
        }

        return $this->cli->parseSnapshotFileOptions($options, $arg, '.phpprobe-api-baseline.json');
    }

    /**
     * @return array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}}
     */
    private function result(array $snapshot, string $baselinePath): array
    {
        $baseline = $this->loadBaseline($baselinePath);
        $currentSymbols = $this->keyedSymbols($snapshot);
        $baselineSymbols = $this->keyedSymbols($baseline);
        $added = [];
        $removed = [];
        $changed = [];

        if ($baselinePath !== '') {
            foreach ($currentSymbols as $id => $symbol) {
                if (!isset($baselineSymbols[$id])) {
                    $added[] = $id;

                    continue;
                }

                if (($symbol['fingerprint'] ?? null) !== ($baselineSymbols[$id]['fingerprint'] ?? null)) {
                    $changed[] = $id;
                }
            }

            foreach ($baselineSymbols as $id => $_symbol) {
                if (!isset($currentSymbols[$id])) {
                    $removed[] = $id;
                }
            }
        }

        sort($added);
        sort($removed);
        sort($changed);

        return [
            'snapshot' => $snapshot,
            'baseline' => $baseline,
            'changed' => $added !== [] || $removed !== [] || $changed !== [],
            'changes' => [
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
        ];
    }

    private function shouldFail(array $result, array $options): bool
    {
        if (($options['baseline'] ?? '') === '' || ($result['changed'] ?? false) !== true) {
            return false;
        }

        return match ($options['failOn']) {
            'error' => false,
            'warning', 'info' => true,
            default => true,
        };
    }

    private function writeBaseline(array $snapshot, string $path): void
    {
        try {
            $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Could not encode API baseline JSON for %s: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException(sprintf('Failed to write API baseline file: %s', $path));
        }
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     * @param array<string, mixed> $options
     */
    private function writeResult(array $result, array $options, bool $failed): void
    {
        match ($options['format']) {
            'json' => $this->writeJson($result),
            'markdown' => $this->writeMarkdown($result, $options, $failed),
            'sarif' => $this->writeSarif($result),
            default => $this->writeText($result, $options, $failed),
        };
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     */
    private function writeJson(array $result): void
    {
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     * @param array{baseline:string,writeBaseline:string,failOn:string} $options
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        if ($options['writeBaseline'] !== '') {
            fwrite(STDOUT, Ansi::color(sprintf('Public API baseline written: %s', $options['writeBaseline']), 'cyan', STDOUT) . PHP_EOL);
        }

        $symbolCount = count($result['snapshot']['symbols'] ?? []);

        if ($options['baseline'] === '') {
            fwrite(STDOUT, Ansi::color(sprintf('Public API snapshot OK: %d symbol(s) scanned.', $symbolCount), 'green', STDOUT) . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

            return;
        }

        if (!$result['changed']) {
            fwrite(STDOUT, Ansi::color(sprintf('Public API unchanged: %d symbol(s) scanned.', $symbolCount), 'green', STDOUT) . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

            return;
        }

        fwrite(STDERR, Ansi::color('Public API snapshot changed:', 'red', STDERR) . PHP_EOL);

        $labels = ['added' => 'Added', 'removed' => 'Removed', 'changed' => 'Changed'];

        foreach (['added', 'removed', 'changed'] as $type) {
            $items = $result['changes'][$type];

            if ($items === []) {
                continue;
            }

            fwrite(STDERR, sprintf('  %s (%d)', $labels[$type], count($items)) . PHP_EOL);

            foreach ($items as $symbol) {
                fwrite(STDERR, sprintf('    - %s', $symbol) . PHP_EOL);
            }
        }

        fwrite(STDERR, $this->summaryFooter($result, $options, $failed) . PHP_EOL);
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     * @param array{baseline:string,failOn:string} $options
     */
    private function writeMarkdown(array $result, array $options, bool $failed): void
    {
        $symbolCount = count($result['snapshot']['symbols'] ?? []);
        $lines = [
            '# PHPProbe API Snapshot Report',
            '',
            sprintf('- Symbols scanned: `%d`', $symbolCount),
            sprintf('- Baseline: `%s`', $options['baseline'] !== '' ? $options['baseline'] : '(none)'),
            sprintf('- Changed: `%s`', $result['changed'] ? 'yes' : 'no'),
            sprintf('- Fail-on: `%s`', $options['failOn']),
            sprintf('- Status: `%s`', $failed ? 'FAIL' : 'PASS'),
            '',
        ];

        $lines[] = '| Change Type | Symbol |';
        $lines[] = '| --- | --- |';

        foreach (['added', 'removed', 'changed'] as $type) {
            foreach ($result['changes'][$type] as $symbol) {
                $lines[] = sprintf('| %s | `%s` |', ucfirst($type), $symbol);
            }
        }

        if ($result['changes']['added'] === [] && $result['changes']['removed'] === [] && $result['changes']['changed'] === []) {
            $lines[] = '| None | - |';
        }

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     */
    private function writeSarif(array $result): void
    {
        $results = [];

        foreach (['added', 'removed', 'changed'] as $type) {
            foreach ($result['changes'][$type] as $symbol) {
                $results[] = [
                    'ruleId' => 'api_snapshot_' . $type,
                    'level' => 'warning',
                    'message' => ['text' => sprintf('Public API %s symbol: %s', $type, $symbol)],
                ];
            }
        }

        fwrite(STDOUT, json_encode(Sarif::payload($results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     * @param array{baseline:string,failOn:string} $options
     */
    private function summaryFooter(array $result, array $options, bool $failed): string
    {
        return sprintf(
            'Summary: symbols=%d changes=%d fail-on=%s baseline=%s status=%s',
            count($result['snapshot']['symbols'] ?? []),
            count($result['changes']['added']) + count($result['changes']['removed']) + count($result['changes']['changed']),
            $options['failOn'],
            $options['baseline'] !== '' ? 'on' : 'off',
            $failed ? 'FAIL' : 'PASS',
        );
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     * @param array{summaryJson:string,baseline:string,failOn:string} $options
     */
    private function writeSummaryJson(array $result, array $options, int $exitCode): void
    {
        if ($options['summaryJson'] === '') {
            return;
        }

        SummaryJson::write($options['summaryJson'], [
            'checker' => 'api',
            'exit_code' => $exitCode,
            'fail_on' => $options['failOn'],
            'has_baseline' => $options['baseline'] !== '',
            'symbols' => count($result['snapshot']['symbols'] ?? []),
            'changed' => $result['changed'],
            'changes' => [
                'added' => count($result['changes']['added']),
                'removed' => count($result['changes']['removed']),
                'changed' => count($result['changes']['changed']),
            ],
        ]);
    }
}

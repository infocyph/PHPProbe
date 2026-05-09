<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Api\ApiSnapshotIndex;
use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Util\BaselineJson;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

final readonly class ApiSnapshotChecker
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
        return CheckerRuntime::guarded(fn(): int => $this->runWithOptions($this->parseArgs($args)));
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $baseline
     * @return array{impact:string,reason:string}
     */
    private function classifyChangedSymbol(array $current, array $baseline): array
    {
        $kind = (string) ($current['kind'] ?? $baseline['kind'] ?? '');

        if (in_array($kind, ['class', 'interface', 'trait', 'enum'], true)) {
            $currentMembers = $this->membersById($current['members'] ?? []);
            $baselineMembers = $this->membersById($baseline['members'] ?? []);

            foreach ($baselineMembers as $id => $baselineMember) {
                if (!isset($currentMembers[$id])) {
                    return ['impact' => 'breaking', 'reason' => 'Member removed: ' . $id];
                }

                if (json_encode($currentMembers[$id], JSON_UNESCAPED_SLASHES) !== json_encode($baselineMember, JSON_UNESCAPED_SLASHES)) {
                    return ['impact' => 'breaking', 'reason' => 'Member signature changed: ' . $id];
                }
            }

            foreach ($currentMembers as $id => $_currentMember) {
                if (!isset($baselineMembers[$id])) {
                    return ['impact' => 'additive', 'reason' => 'New member added: ' . $id];
                }
            }

            return ['impact' => 'internal', 'reason' => 'Class-like symbol changed without member-level drift.'];
        }

        if ($kind === 'function' || $kind === 'constant') {
            return ['impact' => 'breaking', 'reason' => 'Callable/constant signature changed.'];
        }

        return ['impact' => 'internal', 'reason' => 'Symbol changed.'];
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
            '  --preset=NAME                    apply preset: default, standard, ci, or strict',
            '  --exclude=PATH                   skip a path (repeatable)',
            '  --public-only                    ignore protected members',
            '  --include-protected              include protected members (default)',
            '  --baseline=FILE                  compare against a public API snapshot',
            '  --write-baseline[=FILE]          write the current public API snapshot and exit 0',
            '  --format=text|json|markdown|sarif|github output format (default: text)',
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
        $decoded = BaselineJson::readObject($path, 'API');

        $symbols = is_array($decoded['symbols'] ?? null) ? array_values(array_filter(
            $decoded['symbols'],
            is_array(...),
        )) : [];

        return [
            'version' => is_int($decoded['version'] ?? null) ? $decoded['version'] : 1,
            'generated_at' => is_string($decoded['generated_at'] ?? null) ? $decoded['generated_at'] : '',
            'symbols' => $symbols,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function membersById(mixed $members): array
    {
        if (!is_array($members)) {
            return [];
        }

        $index = [];

        foreach ($members as $member) {
            if (is_array($member) && is_string($member['id'] ?? null)) {
                $index[$member['id']] = $member;
            }
        }

        ksort($index);

        return $index;
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
        if ($this->cli->parseCommonCheckerOptions($args, $index, $options, $arg, true)) {
            return true;
        }

        if ($arg === '--public-only' || $arg === '--include-protected') {
            $options['includeProtected'] = $arg === '--include-protected';

            return true;
        }

        return $this->cli->parseSnapshotFileOptions($options, $arg, '.phpprobe-api-baseline.json');
    }

    /**
     * @return array{
     *     snapshot:array<string, mixed>,
     *     baseline:array<string, mixed>,
     *     changed:bool,
     *     changes:array{added:list<string>,removed:list<string>,changed:list<string>},
     *     classifications:array{
     *         added:list<array{id:string,impact:string,reason:string}>,
     *         removed:list<array{id:string,impact:string,reason:string}>,
     *         changed:list<array{id:string,impact:string,reason:string}>
     *     },
     *     impact:array{breaking:int,additive:int,internal:int}
     * }
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
        $classifications = [
            'added' => array_map(static fn(string $id): array => ['id' => $id, 'impact' => 'additive', 'reason' => 'New symbol added.'], $added),
            'removed' => array_map(static fn(string $id): array => ['id' => $id, 'impact' => 'breaking', 'reason' => 'Symbol removed.'], $removed),
            'changed' => [],
        ];

        foreach ($changed as $id) {
            $impact = $this->classifyChangedSymbol($currentSymbols[$id] ?? [], $baselineSymbols[$id] ?? []);
            $classifications['changed'][] = ['id' => $id, ...$impact];
        }

        $impactCounts = ['breaking' => 0, 'additive' => 0, 'internal' => 0];

        foreach (['added', 'removed', 'changed'] as $type) {
            foreach ($classifications[$type] as $item) {
                $level = $item['impact'];
                $impactCounts[$level] = ($impactCounts[$level] ?? 0) + 1;
            }
        }

        return [
            'snapshot' => $snapshot,
            'baseline' => $baseline,
            'changed' => $added !== [] || $removed !== [] || $changed !== [],
            'changes' => [
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
            'classifications' => $classifications,
            'impact' => $impactCounts,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runWithOptions(array $options): int
    {
        if ($options['help']) {
            return $this->help();
        }

        $files = CheckerRuntime::phpFiles($options);
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

    private function writeBaseline(array $snapshot, string $path): void
    {
        BaselineJson::writeObject($path, $snapshot, 'API');
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>},classifications:array{added:list<array{id:string,impact:string,reason:string}>,removed:list<array{id:string,impact:string,reason:string}>,changed:list<array{id:string,impact:string,reason:string}>},impact:array{breaking:int,additive:int,internal:int}} $result
     */
    private function writeGithub(array $result): void
    {
        foreach (['added', 'removed', 'changed'] as $type) {
            foreach ($result['classifications'][$type] as $item) {
                $level = $item['impact'] === 'breaking' ? 'error' : ($item['impact'] === 'additive' ? 'warning' : 'notice');
                fwrite(STDOUT, GithubAnnotation::emit(
                    $level,
                    'PHPProbe api',
                    sprintf('%s: %s (%s)', ucfirst($type), $item['id'], $item['reason']),
                ) . PHP_EOL);
            }
        }

        if (($result['changed'] ?? false) !== true) {
            fwrite(STDOUT, GithubAnnotation::emit('notice', 'PHPProbe api', 'Public API unchanged.') . PHP_EOL);
        }
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     */
    private function writeJson(array $result): void
    {
        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to encode API snapshot output as JSON.');
        }

        fwrite(STDOUT, $encoded . PHP_EOL);
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
     * @param array<string, mixed> $options
     */
    private function writeResult(array $result, array $options, bool $failed): void
    {
        if ($options['format'] === 'markdown') {
            $this->writeMarkdown($result, $options, $failed);

            return;
        }

        if ($options['format'] === 'sarif') {
            $this->writeSarif($result);

            return;
        }

        if ($options['format'] === 'github') {
            $this->writeGithub($result);

            return;
        }

        if ($options['format'] !== 'json') {
            $this->writeText($result, $options, $failed);

            return;
        }

        $this->writeJson($result);
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
}

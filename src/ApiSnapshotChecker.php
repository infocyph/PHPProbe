<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Api\ApiSnapshotIndex;
use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Filesystem\PhpFileFinder;

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
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }
    }

    /**
     * @param array{help:bool,json:bool,config:string,preset:string,includeProtected:bool,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     */
    private function runWithOptions(array $options): int
    {
        if ($options['help']) {
            return $this->help();
        }

        $files = (new PhpFileFinder())->find($options['paths'], $options['excludes']);
        $snapshot = (new ApiSnapshotIndex())->build($files, ['includeProtected' => $options['includeProtected']]);
        $result = $this->result($snapshot, $options['baseline']);

        if ($options['writeBaseline'] !== '') {
            $this->writeBaseline($snapshot, $options['writeBaseline']);
        }

        $this->writeResult($result, $options);

        return $options['writeBaseline'] !== '' || !$result['changed'] ? 0 : 1;
    }
    /**
     * @return array{help:bool,json:bool,config:string,preset:string,includeProtected:bool,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
     */
    private function defaultOptions(): array
    {
        return [
            'help' => false,
            'json' => false,
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
            '  --config=FILE                  read PHPProbe checker settings',
            '  --preset=NAME                  apply preset: phpstorm, standard, or strict',
            '  --exclude=PATH                 skip a path (repeatable)',
            '  --public-only                  ignore protected members',
            '  --include-protected            include protected members (default)',
            '  --baseline=FILE                compare against a public API snapshot',
            '  --write-baseline[=FILE]        write the current public API snapshot and exit 0',
            '  --json                         output machine-readable JSON',
            '  --help                         show this help',
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
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return ['version' => 1, 'generated_at' => '', 'symbols' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return ['version' => 1, 'generated_at' => '', 'symbols' => []];
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
     * @return array{help:bool,json:bool,config:string,preset:string,includeProtected:bool,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
     */
    private function parseArgs(array $args): array
    {
        $options = $this->defaultOptions();
        $options['config'] = $this->cli->configPath($args, $options['config']);
        $config = PhpProbeConfig::fromFile($options['config']);
        $options = $this->cli->mergeConfigWithPreset($config, $this->cli->presetName($args))->applyApiOptions($options);
        $configuredPaths = $options['paths'];
        $options['paths'] = [];

        $index = 0;
        $argCount = count($args);

        while ($index < $argCount) {
            $arg = $args[$index];

            if (!$this->cli->skipConfig($args, $index, $arg)
                && !$this->cli->skipPreset($args, $index, $arg)
                && !$this->parseCliOption($args, $index, $options, $arg)) {
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
     * @param array{help:bool,json:bool,config:string,preset:string,includeProtected:bool,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
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

        if ($arg === '--json') {
            $options['json'] = true;

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

    private function writeBaseline(array $snapshot, string $path): void
    {
        file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{snapshot:array<string, mixed>,baseline:array<string, mixed>,changed:bool,changes:array{added:list<string>,removed:list<string>,changed:list<string>}} $result
     * @param array{json:bool,baseline:string,writeBaseline:string} $options
     */
    private function writeResult(array $result, array $options): void
    {
        if ($options['json']) {
            fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return;
        }

        if ($options['writeBaseline'] !== '') {
            fwrite(STDOUT, sprintf('Public API baseline written: %s', $options['writeBaseline']) . PHP_EOL);
        }

        $symbolCount = count($result['snapshot']['symbols'] ?? []);

        if ($options['baseline'] === '') {
            fwrite(STDOUT, sprintf('Public API snapshot OK: %d symbol(s) scanned.', $symbolCount) . PHP_EOL);

            return;
        }

        if (!$result['changed']) {
            fwrite(STDOUT, sprintf('Public API unchanged: %d symbol(s) scanned.', $symbolCount) . PHP_EOL);

            return;
        }

        fwrite(STDERR, 'Public API snapshot changed:' . PHP_EOL);

        foreach (['added', 'removed', 'changed'] as $type) {
            foreach ($result['changes'][$type] as $symbol) {
                fwrite(STDERR, sprintf('  - %s: %s', $type, $symbol) . PHP_EOL);
            }
        }
    }
}

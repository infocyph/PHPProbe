<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Detection\DuplicateCloneReducer;
use Infocyph\PHPProbe\Detection\DuplicateDetectionEngine;
use Infocyph\PHPProbe\Util\BaselineJson;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\ScopedTempFile;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

final class DuplicateChecker
{
    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        return CheckerRuntime::guarded(function () use ($args): int {
            $options = $this->parseArgs($args);

            if ($options['help']) {
                return $this->help();
            }

            return $this->finishRun($this->buildResult($options), $options);
        });
    }

    /**
     * @param array<string, mixed> $options
     * @return array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>}
     */
    private function analyzeWithCache(array $files, array $options): array
    {
        $engineOptions = [
            'mode' => $options['mode'],
            'normalize' => $options['normalize'],
            'fuzzy' => $options['fuzzy'],
            'nearMiss' => $options['nearMiss'],
            'minLines' => $options['minLines'],
            'minTokens' => $options['minTokens'],
            'minStatements' => $options['minStatements'],
            'minSimilarity' => $options['minSimilarity'],
        ];
        $cacheKey = $this->cacheKey($files, $engineOptions);

        if ($options['cacheEnabled']) {
            $cache = $this->loadCache($options['cacheFile']);

            if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
                $hit = $cache[$cacheKey];

                return [
                    ...$hit,
                    'cache_hit' => true,
                ];
            }
        }

        $result = (new DuplicateDetectionEngine())->analyze($files, $engineOptions);
        $result['cache_hit'] = false;

        if ($options['cacheEnabled']) {
            $cache = $this->loadCache($options['cacheFile']);
            $cache[$cacheKey] = [
                ...$result,
                'cache_hit' => false,
            ];
            $this->saveCache($options['cacheFile'], $cache);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>}
     */
    private function buildResult(array $options): array
    {
        $result = $this->analyzeWithCache(CheckerRuntime::phpFiles($options), $options);

        if ($options['baseline'] !== '') {
            $result = $this->withoutBaselineClones($result, $options['baseline']);
        }

        if (($options['ignoreFingerprints'] ?? []) !== []) {
            $result = $this->withoutIgnoredFingerprints($result, $options['ignoreFingerprints']);
        }

        if ($options['writeBaseline'] !== '') {
            $this->writeBaseline($result, $options['writeBaseline']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $engineOptions
     */
    private function cacheKey(array $files, array $engineOptions): string
    {
        $fingerprint = [];

        foreach ($files as $file) {
            $fingerprint[] = [$file, $this->safeFilesize($file), $this->safeFilemtime($file)];
        }

        return hash('sha256', json_encode([$engineOptions, $fingerprint], JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function defaultCacheFile(): string
    {
        return ScopedTempFile::forProject('.phpprobe-duplicates-cache.json', '.phpprobe-duplicates-cache');
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
            'cacheEnabled' => true,
            'cacheFile' => $this->defaultCacheFile(),
            'errorDuplicatePercentage' => 20.0,
            'config' => Paths::config('phpprobe.json'),
            'mode' => 'gate',
            'normalize' => true,
            'fuzzy' => false,
            'nearMiss' => false,
            'minLines' => 5,
            'minTokens' => 70,
            'minStatements' => 4,
            'minSimilarity' => 0.85,
            'baseline' => '',
            'writeBaseline' => '',
            'ignoreFingerprints' => [],
            'paths' => [],
            'excludes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     */
    private function finishRun(array $result, array $options): int
    {
        $failed = $this->shouldFail($result, $options);
        $exitCode = $options['writeBaseline'] === '' && $failed ? 1 : 0;
        $this->writeResult($result, $options, $failed);
        $this->writeSummaryJson($result, $options, $exitCode);

        return $exitCode;
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe duplicates [options] [paths...]',
            '',
            'Options:',
            '  --mode=gate|audit                gate is deterministic; audit enables structural matching',
            '  --config=FILE                    read PHPProbe checker settings',
            '  --preset=NAME                    apply preset: default, standard, ci, or strict',
            '  --exclude=PATH                   skip a path (repeatable)',
            '  --min-lines=N                    minimum duplicated lines (default: 5)',
            '  --min-tokens=N                   token fingerprint window size (default: 70)',
            '  --min-statements=N               statement window size for audit mode (default: 4)',
            '  --min-similarity=N               near-miss threshold, 0.0-1.0 or 0-100 (default: 0.85)',
            '  --near-miss                      enable bounded statement/shape similarity matching',
            '  --exact                          do not normalize variables/literals',
            '  --fuzzy                          also normalize identifiers/calls',
            '  --baseline=FILE                  suppress clone groups already in a baseline',
            '  --write-baseline[=FILE]          write current clone groups to a baseline and exit 0',
            '  --format=text|json|markdown|sarif|github output format (default: text)',
            '  --json                           alias for --format=json',
            '  --fail-on=error|warning|info     failure threshold (default: warning)',
            '  --summary-json=FILE              write machine-readable run summary',
            '  --changed-only                   scan only changed PHP files from Git diff',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --no-cache                       disable duplicate result cache',
            '  --cache-file=FILE                duplicate result cache file path',
            '  --error-duplicate-percentage=N   error threshold used with --fail-on=error',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @return array<string, true>
     */
    private function knownFingerprints(string $baselinePath): array
    {
        return BaselineJson::knownFingerprints($baselinePath, 'Duplicate', 'clones');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadCache(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeMode(array $options): array
    {
        $options['mode'] = in_array($options['mode'], ['gate', 'audit'], true) ? $options['mode'] : 'gate';

        if ($options['mode'] === 'audit') {
            $options['nearMiss'] = true;
        }

        return $options;
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function parseArgs(array $args): array
    {
        $cli = new CliOptions();
        $options = $this->defaultOptions();
        $options['config'] = $cli->configPath($args, $options['config']);
        $config = PhpProbeConfig::fromFile($options['config']);
        $options = $cli->mergeConfigWithPreset($config, $cli->presetName($args))->applyDuplicateOptions($options);
        $options = $this->normalizeMode($options);
        $configuredPaths = $options['paths'];
        $cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg, $cli),
            'Unknown option for duplicates command: %s',
        );

        return $options;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $options
     */
    private function parseCliOption(array $args, int &$index, array &$options, string $arg, CliOptions $cli): bool
    {
        $mode = $cli->optionValue($arg, '--mode');

        if ($mode !== null) {
            $options['mode'] = in_array($mode, ['gate', 'audit'], true) ? $mode : 'gate';
            $options['nearMiss'] = $options['mode'] === 'audit';

            return true;
        }

        $errorDuplicatePercentage = $cli->optionValue($arg, '--error-duplicate-percentage');

        if ($errorDuplicatePercentage !== null) {
            $options['errorDuplicatePercentage'] = max(0.0, min(100.0, (float) $errorDuplicatePercentage));

            return true;
        }

        $cacheFile = $cli->optionValue($arg, '--cache-file');

        if ($cacheFile !== null) {
            $options['cacheFile'] = trim($cacheFile);

            return true;
        }

        if ($arg === '--no-cache') {
            $options['cacheEnabled'] = false;

            return true;
        }

        return $cli->parseExclude($args, $index, $options, $arg)
            || $this->parseFlag($options, $arg)
            || $this->parseNumericOption($options, $arg, $cli)
            || $cli->parseOutputFormat($options, $arg)
            || $cli->parseFailOn($options, $arg)
            || $cli->parseSummaryJson($options, $arg)
            || $cli->parseChangedOptions($options, $arg)
            || $cli->parseSnapshotFileOptions($options, $arg, '.phpprobe-duplicates-baseline.json');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function parseFlag(array &$options, string $arg): bool
    {
        $flagMap = [
            '--help' => 'help',
            '-h' => 'help',
            '--fuzzy' => 'fuzzy',
            '--near-miss' => 'nearMiss',
        ];

        if (isset($flagMap[$arg])) {
            $options[$flagMap[$arg]] = true;

            return true;
        }

        if ($arg === '--exact') {
            $options['normalize'] = false;
            $options['fuzzy'] = false;

            return true;
        }

        if ($arg === '--no-fuzzy') {
            $options['fuzzy'] = false;

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function parseNumericOption(array &$options, string $arg, CliOptions $cli): bool
    {
        foreach (['--min-lines' => 'minLines', '--min-tokens' => 'minTokens', '--min-statements' => 'minStatements'] as $name => $key) {
            $value = $cli->optionValue($arg, $name);

            if ($value !== null) {
                $options[$key] = max(1, (int) $value);

                return true;
            }
        }

        $similarity = $cli->optionValue($arg, '--min-similarity');

        if ($similarity === null) {
            return false;
        }

        $value = (float) $similarity;
        $options['minSimilarity'] = $value > 1.0 ? min(100.0, $value) / 100.0 : max(0.0, min(1.0, $value));

        return true;
    }

    private function safeFilemtime(string $file): int
    {
        set_error_handler(static fn(): bool => true);

        try {
            $value = filemtime($file);
        } finally {
            restore_error_handler();
        }

        return is_int($value) ? $value : 0;
    }

    private function safeFilePutContents(string $path, string $contents): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            file_put_contents($path, $contents);
        } finally {
            restore_error_handler();
        }
    }

    private function safeFilesize(string $file): int
    {
        set_error_handler(static fn(): bool => true);

        try {
            $value = filesize($file);
        } finally {
            restore_error_handler();
        }

        return is_int($value) ? $value : 0;
    }

    /**
     * @param array<string, array<string, mixed>> $cache
     */
    private function saveCache(string $path, array $cache): void
    {
        if ($path === '') {
            return;
        }

        try {
            $encoded = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (\JsonException) {
            return;
        }

        $this->safeFilePutContents($path, $encoded);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array<string, mixed> $options
     */
    private function shouldFail(array $result, array $options): bool
    {
        if ($result['clones'] === []) {
            return false;
        }

        return match ($options['failOn']) {
            'error' => $result['duplicate_percentage'] >= $options['errorDuplicatePercentage'],
            'warning', 'info' => true,
            default => true,
        };
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array{failOn:string} $options
     */
    private function summaryFooter(array $result, array $options, bool $failed): string
    {
        return sprintf(
            'Summary: files=%d clone-groups=%d duplicated-lines=%d duplicate%%=%.2f fail-on=%s cache=%s status=%s',
            $result['files'],
            count($result['clones']),
            $result['duplicated_lines'],
            $result['duplicate_percentage'],
            $options['failOn'],
            $result['cache_hit'] ? 'HIT' : 'MISS',
            $failed ? 'FAIL' : 'PASS',
        );
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @return array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>}
     */
    private function withoutBaselineClones(array $result, string $baselinePath): array
    {
        $known = $this->knownFingerprints($baselinePath);

        if ($known === []) {
            return $result;
        }

        $result = $this->withoutKnownFingerprints($result, $known);
        $result['known_clones'] = count($known);

        return $result;
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param list<string> $fingerprints
     * @return array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>}
     */
    private function withoutIgnoredFingerprints(array $result, array $fingerprints): array
    {
        return $this->withoutKnownFingerprints($result, array_fill_keys($fingerprints, true));
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array<string, true> $known
     * @return array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>}
     */
    private function withoutKnownFingerprints(array $result, array $known): array
    {
        $result['clones'] = array_values(array_filter($result['clones'], static fn(array $clone): bool => !isset($known[$clone['fingerprint']])));
        $result['new_clones'] = count($result['clones']);
        $result['duplicated_lines'] = (new DuplicateCloneReducer())->uniqueDuplicatedLines($result['clones']);
        $result['duplicate_percentage'] = $result['total_lines'] > 0
            ? round(($result['duplicated_lines'] / $result['total_lines']) * 100, 2)
            : 0.0;

        return $result;
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     */
    private function writeBaseline(array $result, string $path): void
    {
        $payload = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'clones' => array_map(static fn(array $clone): array => [
                'fingerprint' => $clone['fingerprint'],
                'source' => $clone['source'],
                'score' => $clone['score'],
            ], $result['clones']),
        ];

        BaselineJson::writeObject($path, $payload, 'duplicate');
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     */
    private function writeGithub(array $result, bool $failed): void
    {
        $level = $failed ? 'error' : 'warning';

        foreach ($result['clones'] as $clone) {
            foreach ($clone['occurrences'] as $occurrence) {
                fwrite(STDOUT, GithubAnnotation::emit(
                    $level,
                    'PHPProbe duplicates',
                    sprintf('Duplicate clone (%s, %.0f%% similar)', $clone['source'], $clone['similarity'] * 100),
                    $occurrence['file'],
                    $occurrence['start_line'],
                ) . PHP_EOL);
            }
        }

        if ($result['clones'] === []) {
            fwrite(STDOUT, GithubAnnotation::emit('notice', 'PHPProbe duplicates', 'No duplicate clone groups found.') . PHP_EOL);
        }
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     */
    private function writeJson(array $result): void
    {
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array{failOn:string} $options
     */
    private function writeMarkdown(array $result, array $options, bool $failed): void
    {
        $lines = [
            '# PHPProbe Duplicate Report',
            '',
            sprintf('- Files scanned: `%d`', $result['files']),
            sprintf('- Total lines: `%d`', $result['total_lines']),
            sprintf('- Duplicate lines: `%d`', $result['duplicated_lines']),
            sprintf('- Duplicate percentage: `%.2f%%`', $result['duplicate_percentage']),
            sprintf('- Clone groups: `%d`', count($result['clones'])),
            sprintf('- Cache hit: `%s`', $result['cache_hit'] ? 'yes' : 'no'),
            sprintf('- Fail-on: `%s`', $options['failOn']),
            sprintf('- Status: `%s`', $failed ? 'FAIL' : 'PASS'),
            '',
        ];

        if ($result['clones'] === []) {
            $lines[] = 'No duplicate clone groups found.';
        } else {
            $lines[] = '| # | Source | Similarity | Lines | Location |';
            $lines[] = '| --- | --- | --- | --- | --- |';

            foreach ($result['clones'] as $index => $clone) {
                $first = $clone['occurrences'][0];
                $lines[] = sprintf(
                    '| %d | `%s` | %.0f%% | %d | `%s:%d-%d` |',
                    $index + 1,
                    $clone['source'],
                    $clone['similarity'] * 100,
                    $clone['lines'],
                    $first['file'],
                    $first['start_line'],
                    $first['end_line'],
                );
            }
        }

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array<string, mixed> $options
     */
    private function writeResult(array $result, array $options, bool $failed): void
    {
        if ($options['format'] === 'json') {
            $this->writeJson($result);

            return;
        }

        if ($options['format'] === 'markdown') {
            $this->writeMarkdown($result, $options, $failed);

            return;
        }

        if ($options['format'] === 'sarif') {
            $this->writeSarif($result);

            return;
        }

        if ($options['format'] === 'github') {
            $this->writeGithub($result, $failed);

            return;
        }

        $this->writeText($result, $options, $failed);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     */
    private function writeSarif(array $result): void
    {
        $results = [];

        foreach ($result['clones'] as $clone) {
            foreach ($clone['occurrences'] as $occurrence) {
                $results[] = [
                    'ruleId' => 'duplicate_code_clone',
                    'level' => 'warning',
                    'message' => ['text' => sprintf('Duplicate clone group (%s, %.0f%% similar).', $clone['source'], $clone['similarity'] * 100)],
                    'locations' => [[
                        'physicalLocation' => [
                            'artifactLocation' => ['uri' => $occurrence['file']],
                            'region' => ['startLine' => $occurrence['start_line']],
                        ],
                    ]],
                ];
            }
        }

        fwrite(STDOUT, json_encode(Sarif::payload($results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array{summaryJson:string,failOn:string} $options
     */
    private function writeSummaryJson(array $result, array $options, int $exitCode): void
    {
        SummaryJson::writeCheckerSummary(
            $options['summaryJson'],
            'duplicates',
            $exitCode,
            $options['failOn'],
            [
            'files' => $result['files'],
            'total_lines' => $result['total_lines'],
            'duplicated_lines' => $result['duplicated_lines'],
            'duplicate_percentage' => $result['duplicate_percentage'],
            'clone_groups' => count($result['clones']),
            'cache_hit' => $result['cache_hit'],
            ],
        );
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,cache_hit:bool,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array{writeBaseline:string,failOn:string} $options
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        if ($options['writeBaseline'] !== '') {
            fwrite(STDOUT, Ansi::color(sprintf('Duplicate baseline written: %s', $options['writeBaseline']), 'cyan', STDOUT) . PHP_EOL);
        }

        if ($result['clones'] === []) {
            fwrite(STDOUT, Ansi::color(
                sprintf('No new duplicated code found (%d PHP files, %d lines checked).', $result['files'], $result['total_lines']),
                'green',
                STDOUT,
            ) . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

            return;
        }

        fwrite(STDERR, Ansi::color(sprintf(
            'Found %d clone group(s) with %d duplicated lines in %d PHP files:',
            count($result['clones']),
            $result['duplicated_lines'],
            $result['files'],
        ), 'red', STDERR) . PHP_EOL);

        foreach ($result['clones'] as $index => $clone) {
            $first = $clone['occurrences'][0];
            fwrite(STDERR, sprintf(
                '  %d) %d lines, %.0f%% similar, %s, score %.1f',
                $index + 1,
                $clone['lines'],
                $clone['similarity'] * 100,
                $clone['source'],
                $clone['score'],
            ) . PHP_EOL);
            fwrite(STDERR, sprintf(
                '     %s:%d-%d',
                $first['file'],
                $first['start_line'],
                $first['end_line'],
            ) . PHP_EOL);

            foreach (array_slice($clone['occurrences'], 1) as $occurrence) {
                fwrite(STDERR, sprintf('     %s:%d-%d', $occurrence['file'], $occurrence['start_line'], $occurrence['end_line']) . PHP_EOL);
            }
        }

        fwrite(STDERR, Ansi::color(sprintf('%.2f%% duplicated lines.', $result['duplicate_percentage']), 'yellow', STDERR) . PHP_EOL);
        fwrite(STDERR, $this->summaryFooter($result, $options, $failed) . PHP_EOL);
    }
}

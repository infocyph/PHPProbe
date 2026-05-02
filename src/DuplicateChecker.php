<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Detection\DuplicateDetectionEngine;
use Infocyph\PHPProbe\Filesystem\PhpFileFinder;

final class DuplicateChecker
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
            $options = $this->parseArgs($args);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }

        if ($options['help']) {
            return $this->help();
        }

        $result = (new DuplicateDetectionEngine())->analyze((new PhpFileFinder())->find($options['paths'], $options['excludes']), $options);

        if ($options['baseline'] !== '') {
            $result = $this->withoutBaselineClones($result, $options['baseline']);
        }

        if ($options['writeBaseline'] !== '') {
            $this->writeBaseline($result, $options['writeBaseline']);
        }

        $this->writeResult($result, $options);

        return $options['writeBaseline'] !== '' || $result['clones'] === [] ? 0 : 1;
    }

    /**
     * @return array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
     */
    private function defaultOptions(): array
    {
        return [
            'help' => false,
            'json' => false,
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
            'paths' => [],
            'excludes' => [],
        ];
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe duplicates [options] [paths...]',
            '',
            'Options:',
            '  --mode=gate|audit              gate is deterministic; audit enables structural matching',
            '  --config=FILE                  read PHPProbe checker settings',
            '  --preset=NAME                  apply preset: phpstorm, standard, or strict',
            '  --exclude=PATH                 skip a path (repeatable)',
            '  --min-lines=N                  minimum duplicated lines (default: 5)',
            '  --min-tokens=N                 token fingerprint window size (default: 70)',
            '  --min-statements=N             statement window size for audit mode (default: 4)',
            '  --min-similarity=N             near-miss threshold, 0.0-1.0 or 0-100 (default: 0.85)',
            '  --near-miss                    enable bounded statement/shape similarity matching',
            '  --exact                        do not normalize variables/literals',
            '  --fuzzy                        also normalize identifiers/calls',
            '  --baseline=FILE                suppress clone groups already in a baseline',
            '  --write-baseline[=FILE]        write current clone groups to a baseline and exit 0',
            '  --json                         output machine-readable JSON',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @return array<string, true>
     */
    private function knownFingerprints(string $baselinePath): array
    {
        if (!is_file($baselinePath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($baselinePath), true);
        $clones = is_array($decoded) ? ($decoded['clones'] ?? []) : [];
        $known = [];

        if (!is_array($clones)) {
            return [];
        }

        foreach ($clones as $clone) {
            if (is_array($clone) && is_string($clone['fingerprint'] ?? null)) {
                $known[$clone['fingerprint']] = true;
            }
        }

        return $known;
    }

    /**
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     * @return array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
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
     * @return array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
     */
    private function parseArgs(array $args): array
    {
        $options = $this->defaultOptions();
        $options['config'] = $this->cli->configPath($args, $options['config']);
        $config = PhpProbeConfig::fromFile($options['config']);
        $options = $this->cli->mergeConfigWithPreset($config, $this->cli->presetName($args))->applyDuplicateOptions($options);
        $options = $this->normalizeMode($options);
        $configuredPaths = $options['paths'];
        $options['paths'] = [];

        $argCount = count($args);

        for ($index = 0; $index < $argCount; $index++) {
            $arg = $args[$index];

            if ($this->cli->skipConfig($args, $index, $arg) || $this->cli->skipPreset($args, $index, $arg)) {
                continue;
            }

            if ($this->parseCliOption($args, $index, $options, $arg)) {
                continue;
            }

            $options['paths'][] = $arg;
        }

        if ($options['paths'] === []) {
            $options['paths'] = $configuredPaths;
        }

        return $options;
    }

    /**
     * @param list<string> $args
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseCliOption(array $args, int &$index, array &$options, string $arg): bool
    {
        $mode = $this->cli->optionValue($arg, '--mode');

        if ($mode !== null) {
            $options['mode'] = in_array($mode, ['gate', 'audit'], true) ? $mode : 'gate';
            $options['nearMiss'] = $options['mode'] === 'audit';

            return true;
        }

        return $this->cli->parseExclude($args, $index, $options, $arg)
            || $this->parseFlag($options, $arg)
            || $this->parseNumericOption($options, $arg)
            || $this->cli->parseSnapshotFileOptions($options, $arg, '.phpprobe-duplicates-baseline.json');
    }

    /**
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseFlag(array &$options, string $arg): bool
    {
        $flagMap = [
            '--help' => 'help',
            '-h' => 'help',
            '--json' => 'json',
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
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseNumericOption(array &$options, string $arg): bool
    {
        foreach (['--min-lines' => 'minLines', '--min-tokens' => 'minTokens', '--min-statements' => 'minStatements'] as $name => $key) {
            $value = $this->cli->optionValue($arg, $name);

            if ($value !== null) {
                $options[$key] = max(1, (int) $value);

                return true;
            }
        }

        $similarity = $this->cli->optionValue($arg, '--min-similarity');

        if ($similarity === null) {
            return false;
        }

        $value = (float) $similarity;
        $options['minSimilarity'] = $value > 1.0 ? min(100.0, $value) / 100.0 : max(0.0, min(1.0, $value));

        return true;
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @return array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>}
     */
    private function withoutBaselineClones(array $result, string $baselinePath): array
    {
        $known = $this->knownFingerprints($baselinePath);

        if ($known === []) {
            return $result;
        }

        $result['clones'] = array_values(array_filter($result['clones'], static fn(array $clone): bool => !isset($known[$clone['fingerprint']])));
        $result['known_clones'] = count($known);
        $result['new_clones'] = count($result['clones']);

        return $result;
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
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

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array{json:bool,writeBaseline:string} $options
     */
    private function writeResult(array $result, array $options): void
    {
        if ($options['json']) {
            fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return;
        }

        if ($options['writeBaseline'] !== '') {
            fwrite(STDOUT, sprintf('Duplicate baseline written: %s', $options['writeBaseline']) . PHP_EOL);
        }

        if ($result['clones'] === []) {
            fwrite(STDOUT, sprintf('No new duplicated code found (%d PHP files, %d lines checked).', $result['files'], $result['total_lines']) . PHP_EOL);

            return;
        }

        $this->writeTextClones($result);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     */
    private function writeTextClones(array $result): void
    {
        fwrite(STDERR, sprintf(
            'Found %d clone group(s) with %d duplicated lines in %d PHP files:',
            count($result['clones']),
            $result['duplicated_lines'],
            $result['files'],
        ) . PHP_EOL);

        foreach ($result['clones'] as $clone) {
            $first = $clone['occurrences'][0];
            fwrite(STDERR, sprintf(
                '  - %s:%d-%d (%d lines, %.0f%% similar, %s, score %.1f)',
                $first['file'],
                $first['start_line'],
                $first['end_line'],
                $clone['lines'],
                $clone['similarity'] * 100,
                $clone['source'],
                $clone['score'],
            ) . PHP_EOL);

            foreach (array_slice($clone['occurrences'], 1) as $occurrence) {
                fwrite(STDERR, sprintf('    %s:%d-%d', $occurrence['file'], $occurrence['start_line'], $occurrence['end_line']) . PHP_EOL);
            }
        }

        fwrite(STDERR, sprintf('%.2f%% duplicated lines.', $result['duplicate_percentage']) . PHP_EOL);
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\OptionValues;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Console\CliTable;
use Infocyph\PHPProbe\Filesystem\PhpFileFinder;
use Infocyph\PHPProbe\Reference\ReferenceAnalyzer;
use Infocyph\PHPProbe\Reference\ReferenceFinding;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\InputFileGroups;
use Infocyph\PHPProbe\Util\ProjectPath;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

/**
 * @phpstan-type ReferenceOptions array{help:bool,format:string,color:string,summaryJson:string,changedOnly:bool,changedBase:string,composer:string,textColorSuccess:string,textColorError:string,textColorFile:string,config:string,paths:list<string>,excludes:list<string>}
 * @phpstan-type ReferenceGroup array{name:string,files_checked:int,findings:int,files:list<string>}
 * @phpstan-type ReferenceResult array{files_checked:int,symbols_indexed:int,references_checked:int,extensions_checked:int,extensions_missing:int,findings:list<ReferenceFinding>,groups:list<ReferenceGroup>}
 */
final class ReferenceChecker
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        return CheckerRuntime::guarded(fn(): int => $this->runWithOptions($this->parseArgs($args)));
    }

    /**
     * @param array<string, list<string>> $groups
     * @param list<ReferenceFinding> $findings
     * @return list<ReferenceGroup>
     */
    private function groupSummaries(array $groups, array $findings): array
    {
        $summaries = [];
        $covered = [];

        foreach ($groups as $name => $files) {
            $relativeFiles = array_map(ProjectPath::relative(...), $files);
            $lookup = array_fill_keys($relativeFiles, true);
            $covered += $lookup;
            $summaries[] = [
                'name' => $name,
                'files_checked' => count($files),
                'findings' => count(array_filter($findings, static fn(ReferenceFinding $finding): bool => isset($lookup[$finding->file]))),
                'files' => $relativeFiles,
            ];
        }

        $metadataFindings = array_values(array_filter(
            $findings,
            static fn(ReferenceFinding $finding): bool => !isset($covered[$finding->file]),
        ));

        if ($metadataFindings !== []) {
            $files = array_values(array_unique(array_map(
                static fn(ReferenceFinding $finding): string => $finding->file,
                $metadataFindings,
            )));
            $summaries[] = [
                'name' => 'composer',
                'files_checked' => count($files),
                'findings' => count($metadataFindings),
                'files' => $files,
            ];
        }

        return $summaries;
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe reference [options] [paths...]',
            '',
            'Detect unresolved class-like references, missing required extensions, and Composer PSR-4 declaration mismatches.',
            '',
            'Options:',
            '  --config=FILE                    read PHPProbe checker settings',
            '  --preset=NAME                    apply preset: default, standard, ci, or strict',
            '  --composer=FILE                  Composer metadata file (default: composer.json)',
            '  --exclude=PATH                   skip a path (repeatable)',
            '  --format=text|json|phpstan-json|markdown|sarif|github output format (default: text)',
            '  --color=auto|always|never       ANSI color mode (default: auto)',
            '  --json                           alias for --format=json',
            '  --summary-json=FILE              write machine-readable run summary',
            '  --changed-only                   report references only in changed PHP files',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /** @param list<string> $args
     * @return ReferenceOptions
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
            'composer' => 'composer.json',
            'textColorSuccess' => 'green',
            'textColorError' => 'red',
            'textColorFile' => 'cyan',
            'config' => Paths::config('phpprobe.json'),
            'paths' => [],
            'excludes' => [],
        ];
        $options = $cli->resolvedConfig($args, $options)->applyReferenceOptions($options);
        $configuredPaths = OptionValues::strings($options, 'paths');
        $cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg, $cli),
            'Unknown option for reference command: %s',
        );

        /** @var ReferenceOptions $typed */
        $typed = OptionValues::coerce($options, [
            'help' => 'bool',
            'format' => 'string',
            'color' => 'string',
            'summaryJson' => 'string',
            'changedOnly' => 'bool',
            'changedBase' => 'string',
            'composer' => 'string',
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
     * @param list<string> $args
     * @param array<string, mixed> $options
     */
    private function parseCliOption(array $args, int &$index, array &$options, string $arg, CliOptions $cli): bool
    {
        if ($cli->parseCommonCheckerOptions($args, $index, $options, $arg, false)) {
            return true;
        }

        $composer = $cli->optionValue($arg, '--composer');

        if ($arg === '--composer') {
            $composer = $args[++$index] ?? '';
        }

        if ($composer === null) {
            return false;
        }

        if (trim($composer) === '') {
            throw new \InvalidArgumentException('--composer requires a file path.');
        }

        $options['composer'] = trim($composer);

        return true;
    }

    /** @param ReferenceOptions $options */
    private function runWithOptions(array $options): int
    {
        CheckerRuntime::applyColorMode($options);

        if ($options['help']) {
            return $this->help();
        }

        $analysisFiles = CheckerRuntime::phpFiles($options);
        $indexFiles = new PhpFileFinder()->find($options['paths'], $options['excludes']);
        $result = new ReferenceAnalyzer()->analyze($analysisFiles, $indexFiles, $options['composer']);
        $groups = InputFileGroups::group($analysisFiles, $options['paths']);
        $result['groups'] = $this->groupSummaries($groups, $result['findings']);
        $failed = $result['findings'] !== [];
        $exitCode = $failed ? 1 : 0;

        $this->writeResult($result, $options, $failed);
        SummaryJson::writeIfConfigured($options['summaryJson'], [
            'checker' => 'reference',
            'exit_code' => $exitCode,
            'files_checked' => $result['files_checked'],
            'symbols_indexed' => $result['symbols_indexed'],
            'references_checked' => $result['references_checked'],
            'extensions_checked' => $result['extensions_checked'],
            'extensions_missing' => $result['extensions_missing'],
            'findings' => count($result['findings']),
            'groups' => count($result['groups']),
        ]);

        return $exitCode;
    }

    /** @param ReferenceResult $result */
    private function writeGithub(array $result): void
    {
        foreach ($result['findings'] as $finding) {
            fwrite(STDOUT, GithubAnnotation::emit(
                'error',
                'PHPProbe reference',
                trim($finding->message . ' ' . ($finding->suggestion ?? '')),
                $finding->file,
                $finding->line,
            ) . PHP_EOL);
        }

        if ($result['findings'] === []) {
            fwrite(STDOUT, GithubAnnotation::emit('notice', 'PHPProbe reference', 'No broken references or missing required extensions found.') . PHP_EOL);
        }
    }

    /** @param ReferenceResult $result */
    private function writeJson(array $result): void
    {
        fwrite(STDOUT, json_encode([
            'files_checked' => $result['files_checked'],
            'symbols_indexed' => $result['symbols_indexed'],
            'references_checked' => $result['references_checked'],
            'extensions_checked' => $result['extensions_checked'],
            'extensions_missing' => $result['extensions_missing'],
            'findings' => array_map(static fn(ReferenceFinding $finding): array => $finding->toArray(), $result['findings']),
            'groups' => array_map(static fn(array $group): array => [
                'name' => $group['name'],
                'files_checked' => $group['files_checked'],
                'findings' => $group['findings'],
            ], $result['groups']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /** @param ReferenceResult $result */
    private function writeMarkdown(array $result, bool $failed): void
    {
        $lines = [
            '# PHPProbe Reference Report',
            '',
            sprintf('- Files checked: `%d`', $result['files_checked']),
            sprintf('- Symbols indexed: `%d`', $result['symbols_indexed']),
            sprintf('- References checked: `%d`', $result['references_checked']),
            sprintf('- Required extensions checked: `%d`', $result['extensions_checked']),
            sprintf('- Required extensions missing: `%d`', $result['extensions_missing']),
            sprintf('- Findings: `%d`', count($result['findings'])),
            sprintf('- Status: `%s`', $failed ? 'FAIL' : 'PASS'),
            '',
        ];

        if ($result['findings'] === []) {
            $lines[] = 'No broken references or missing required extensions found.';
        } else {
            $lines[] = '| File | Line | Reference / requirement | Message | Suggestion |';
            $lines[] = '| --- | ---: | --- | --- | --- |';

            foreach ($result['findings'] as $finding) {
                $lines[] = sprintf(
                    '| `%s` | %d | `%s` | %s | %s |',
                    $finding->file,
                    $finding->line,
                    $finding->symbol,
                    str_replace('|', '\\|', $finding->message),
                    str_replace('|', '\\|', $finding->suggestion ?? ''),
                );
            }
        }

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /** @param ReferenceResult $result */
    private function writePhpStanJson(array $result): void
    {
        /** @var array<string, array{errors:int,messages:list<array{message:string,line:int,ignorable:bool,identifier:string,tip?:string}>}> $files */
        $files = [];

        foreach ($result['findings'] as $finding) {
            $files[$finding->file] ??= ['errors' => 0, 'messages' => []];
            $files[$finding->file]['errors']++;
            $message = [
                'message' => $finding->message,
                'line' => $finding->line,
                'ignorable' => false,
                'identifier' => 'reference.' . $finding->type,
            ];

            if ($finding->suggestion !== null) {
                $message['tip'] = $finding->suggestion;
            }

            $files[$finding->file]['messages'][] = $message;
        }

        fwrite(STDOUT, json_encode([
            'totals' => ['errors' => 0, 'file_errors' => count($result['findings'])],
            'files' => (object) $files,
            'errors' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /** @param ReferenceResult $result
     * @param ReferenceOptions $options
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

    /** @param ReferenceResult $result */
    private function writeSarif(array $result): void
    {
        $findings = array_map(static fn(ReferenceFinding $finding): array => [
            'ruleId' => 'reference_' . $finding->type,
            'level' => 'error',
            'message' => ['text' => trim($finding->message . ' ' . ($finding->suggestion ?? ''))],
            'locations' => [[
                'physicalLocation' => [
                    'artifactLocation' => ['uri' => $finding->file],
                    'region' => ['startLine' => $finding->line],
                ],
            ]],
        ], $result['findings']);

        fwrite(STDOUT, json_encode(Sarif::payload($findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /** @param ReferenceResult $result
     * @param ReferenceOptions $options
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        $stream = $failed ? STDERR : STDOUT;
        fwrite($stream, Ansi::color('PHPProbe References', $failed ? $options['textColorError'] : $options['textColorSuccess'], $stream) . PHP_EOL);
        fwrite($stream, CliTable::render(
            ['Group', 'Files', 'Errors'],
            array_map(static fn(array $group): array => [$group['name'], $group['files_checked'], $group['findings']], $result['groups']),
            [0 => 40],
        ) . PHP_EOL);

        if ($result['findings'] === []) {
            fwrite(STDOUT, sprintf(
                'References OK: %d symbols indexed, %d references checked, and %d required extensions loaded.%s',
                $result['symbols_indexed'],
                $result['references_checked'],
                $result['extensions_checked'],
                PHP_EOL,
            ));
        } else {
            $rows = array_map(static fn(ReferenceFinding $finding): array => [
                $finding->file,
                $finding->line,
                $finding->symbol,
                $finding->confidence,
                $finding->suggestion ?? $finding->message,
            ], $result['findings']);
            fwrite(STDERR, CliTable::render(
                ['File', 'Line', 'Reference / requirement', 'Confidence', 'Suggested solution'],
                $rows,
                [0 => 48, 1 => 6, 2 => 56, 3 => 12, 4 => 80],
            ) . PHP_EOL);
        }

        fwrite($stream, sprintf(
            'Summary: files=%d symbols=%d references=%d extensions=%d missing_extensions=%d errors=%d status=%s%s',
            $result['files_checked'],
            $result['symbols_indexed'],
            $result['references_checked'],
            $result['extensions_checked'],
            $result['extensions_missing'],
            count($result['findings']),
            $failed ? 'FAIL' : 'PASS',
            PHP_EOL,
        ));
    }
}

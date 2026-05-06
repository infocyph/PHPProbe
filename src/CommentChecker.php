<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Comment\CommentFinding;
use Infocyph\PHPProbe\Comment\CommentScanner;
use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Filesystem\PhpFileFinder;

final class CommentChecker
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

            if ($options['help']) {
                return $this->help();
            }

            $result = (new CommentScanner())->scan((new PhpFileFinder())->find($options['paths'], $options['excludes']), $options);
            $this->writeResult($result, $options);

            return $this->shouldFail($result['findings'], $options['failOn']) ? 1 : 0;
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }
    }

    /**
     * @return array{
     *     help:bool,
     *     json:bool,
     *     strict:bool,
     *     failOn:string,
     *     config:string,
     *     paths:list<string>,
     *     excludes:list<string>,
     *     scanMarkers:bool,
     *     markerTags:list<string>,
     *     markerSeverity:array<string,string>,
     *     commentedOutEnabled:bool,
     *     allowedReasonTags:list<string>,
     *     optionalReasonTags:list<string>,
     *     allowOptionalReasonTagsInStrictMode:bool,
     *     minReasonLength:int,
     *     maxAllowedBlockLines:int,
     *     requireIssueForBlocksLongerThan:int,
     *     allowedIssuePatterns:list<string>,
     *     allowBlankLineBetweenReasonAndCode:bool,
     *     allowReasonBeforeBlockComment:bool,
     *     allowBlankLineBetweenReasonAndCodeInBlock:bool,
     *     allowPhpdocExamples:bool,
     *     phpdocExampleLabels:list<string>,
     *     typeSeverity:array<string,string>,
     *     strictSeverity:array<string,string>
     * }
     */
    private function defaultOptions(): array
    {
        return [
            'help' => false,
            'json' => false,
            'strict' => false,
            'failOn' => 'error',
            'config' => Paths::config('phpprobe.json'),
            'paths' => [],
            'excludes' => [],
            'scanMarkers' => true,
            'markerTags' => [
                'TODO',
                'FIXME',
                'BUG',
                'HACK',
                'XXX',
                'NOTE',
                'OPTIMIZE',
                'REFACTOR',
                'DEPRECATED',
                'SECURITY',
                'REVIEW',
                'QUESTION',
                'WARNING',
            ],
            'markerSeverity' => [
                'SECURITY' => 'critical',
                'BUG' => 'high',
                'FIXME' => 'high',
                'HACK' => 'medium',
                'XXX' => 'medium',
                'WARNING' => 'medium',
                'TODO' => 'low',
                'OPTIMIZE' => 'low',
                'REFACTOR' => 'low',
                'DEPRECATED' => 'low',
                'REVIEW' => 'info',
                'QUESTION' => 'info',
                'NOTE' => 'info',
            ],
            'commentedOutEnabled' => true,
            'allowedReasonTags' => ['TODO', 'FIXME', 'BUG', 'HACK', 'SECURITY', 'REVIEW', 'DEPRECATED'],
            'optionalReasonTags' => ['TEMP', 'DEBUG', 'EXPERIMENTAL'],
            'allowOptionalReasonTagsInStrictMode' => false,
            'minReasonLength' => 12,
            'maxAllowedBlockLines' => 10,
            'requireIssueForBlocksLongerThan' => 3,
            'allowedIssuePatterns' => ['/#\d+/', '/[A-Z]+-\d+/'],
            'allowBlankLineBetweenReasonAndCode' => false,
            'allowReasonBeforeBlockComment' => true,
            'allowBlankLineBetweenReasonAndCodeInBlock' => true,
            'allowPhpdocExamples' => true,
            'phpdocExampleLabels' => ['Example:', 'Examples:', 'Usage:', 'Snippet:', 'Code sample:'],
            'typeSeverity' => [
                'comment_marker' => 'info',
                'commented_out_code_without_reason' => 'warning',
                'commented_out_code_without_valid_tag' => 'warning',
                'commented_out_code_without_valid_reason' => 'warning',
                'commented_out_code_with_weak_reason' => 'warning',
                'commented_out_code_with_valid_reason' => 'info',
                'commented_out_code_block_too_large' => 'error',
                'commented_out_code_requires_issue_reference' => 'warning',
                'commented_out_code_in_phpdoc_without_example_label' => 'warning',
            ],
            'strictSeverity' => [
                'commented_out_code_without_reason' => 'error',
                'commented_out_code_without_valid_tag' => 'error',
                'commented_out_code_without_valid_reason' => 'error',
                'commented_out_code_with_weak_reason' => 'error',
                'commented_out_code_block_too_large' => 'error',
            ],
        ];
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe comments [options] [paths...]',
            '',
            'Options:',
            '  --config=FILE                  read PHPProbe checker settings',
            '  --preset=NAME                  apply preset: phpstorm, standard, or strict',
            '  --exclude=PATH                 skip a path (repeatable)',
            '  --json                         output machine-readable JSON',
            '  --strict                       enforce strict policy severities',
            '  --fail-on=error|warning|info   minimum severity level to fail',
            '  --tags=TODO,FIXME,...          override marker tags',
            '  --help                         show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{
     *     help:bool,
     *     json:bool,
     *     strict:bool,
     *     failOn:string,
     *     config:string,
     *     paths:list<string>,
     *     excludes:list<string>,
     *     scanMarkers:bool,
     *     markerTags:list<string>,
     *     markerSeverity:array<string,string>,
     *     commentedOutEnabled:bool,
     *     allowedReasonTags:list<string>,
     *     optionalReasonTags:list<string>,
     *     allowOptionalReasonTagsInStrictMode:bool,
     *     minReasonLength:int,
     *     maxAllowedBlockLines:int,
     *     requireIssueForBlocksLongerThan:int,
     *     allowedIssuePatterns:list<string>,
     *     allowBlankLineBetweenReasonAndCode:bool,
     *     allowReasonBeforeBlockComment:bool,
     *     allowBlankLineBetweenReasonAndCodeInBlock:bool,
     *     allowPhpdocExamples:bool,
     *     phpdocExampleLabels:list<string>,
     *     typeSeverity:array<string,string>,
     *     strictSeverity:array<string,string>
     * }
     */
    private function parseArgs(array $args): array
    {
        $options = $this->defaultOptions();
        $options['config'] = $this->cli->configPath($args, $options['config']);
        $config = $this->cli->mergeConfigWithPreset(PhpProbeConfig::fromFile($options['config']), $this->cli->presetName($args));
        $options = $config->applyCommentOptions($options);
        $configuredPaths = $options['paths'];
        $options['paths'] = [];
        $collectingPathsOnly = false;
        $argCount = count($args);

        for ($index = 0; $index < $argCount; $index++) {
            $arg = $args[$index];

            if ($collectingPathsOnly) {
                $options['paths'][] = $arg;

                continue;
            }

            if ($arg === '--') {
                $collectingPathsOnly = true;

                continue;
            }

            if ($this->cli->skipConfig($args, $index, $arg) || $this->cli->skipPreset($args, $index, $arg)) {
                continue;
            }

            if ($this->parseCliOption($args, $index, $options, $arg)) {
                continue;
            }

            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException(sprintf('Unknown option for comments command: %s', $arg));
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
     * @param array{
     *     help:bool,
     *     json:bool,
     *     strict:bool,
     *     failOn:string,
     *     excludes:list<string>,
     *     markerTags:list<string>
     * } $options
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

        if ($arg === '--strict') {
            $options['strict'] = true;

            return true;
        }

        $failOn = $this->cli->optionValue($arg, '--fail-on');

        if ($failOn !== null) {
            $normalized = strtolower(trim($failOn));

            if (!in_array($normalized, ['error', 'warning', 'info'], true)) {
                throw new \InvalidArgumentException(sprintf('Invalid --fail-on value "%s". Expected: error, warning, info.', $failOn));
            }

            $options['failOn'] = $normalized;

            return true;
        }

        $tags = $this->cli->optionValue($arg, '--tags');

        if ($tags !== null) {
            $options['markerTags'] = array_values(array_filter(
                array_map(
                    static fn(string $tag): string => strtoupper(trim($tag)),
                    explode(',', $tags),
                ),
                static fn(string $tag): bool => $tag !== '',
            ));

            return true;
        }

        return false;
    }

    /**
     * @param list<CommentFinding> $findings
     */
    private function shouldFail(array $findings, string $failOn): bool
    {
        $threshold = match ($failOn) {
            'info' => 1,
            'warning' => 4,
            default => 6,
        };

        foreach ($findings as $finding) {
            if ($this->severityRank($finding->severity) >= $threshold) {
                return true;
            }
        }

        return false;
    }

    private function severityRank(string $severity): int
    {
        return match (strtolower($severity)) {
            'error' => 7,
            'critical' => 6,
            'high' => 5,
            'warning' => 4,
            'medium' => 3,
            'low' => 2,
            default => 1,
        };
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>} $result
     * @param array{json:bool} $options
     */
    private function writeResult(array $result, array $options): void
    {
        if ($options['json']) {
            fwrite(STDOUT, json_encode([
                'files' => $result['files'],
                'findings' => array_map(
                    static fn(CommentFinding $finding): array => $finding->toArray(),
                    $result['findings'],
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return;
        }

        if ($result['findings'] === []) {
            fwrite(STDOUT, Ansi::color(sprintf('No comment policy findings (%d PHP files scanned).', $result['files']), 'green', STDOUT) . PHP_EOL);

            return;
        }

        $grouped = [];

        foreach ($result['findings'] as $finding) {
            $grouped[$finding->file][] = $finding;
        }

        fwrite(
            STDERR,
            Ansi::color(
                sprintf(
                    'Comment policy findings: %d issue(s) in %d file(s) scanned.',
                    count($result['findings']),
                    count($grouped),
                ),
                'red',
                STDERR,
            ) . PHP_EOL,
        );

        foreach ($grouped as $file => $findings) {
            fwrite(STDERR, Ansi::color($file, 'cyan', STDERR) . PHP_EOL);

            foreach ($findings as $finding) {
                $lineLabel = $finding->line === $finding->endLine
                    ? (string) $finding->line
                    : sprintf('%d-%d', $finding->line, $finding->endLine);
                $title = $this->findingTitle($finding->type);

                fwrite(
                    STDERR,
                    sprintf(
                        '  %s  L%s  %s',
                        Ansi::severity($finding->severity, STDERR),
                        $lineLabel,
                        $title,
                    ) . PHP_EOL,
                );
                fwrite(STDERR, '      ' . $finding->message . PHP_EOL);
            }
        }
    }

    private function findingTitle(string $type): string
    {
        return match ($type) {
            'comment_marker' => 'Comment Marker',
            'commented_out_code_without_reason' => 'Commented-out Code Without Reason',
            'commented_out_code_without_valid_tag' => 'Commented-out Code Uses Invalid Reason Tag',
            'commented_out_code_without_valid_reason' => 'Commented-out Code Without Valid Reason',
            'commented_out_code_with_weak_reason' => 'Commented-out Code With Weak Reason',
            'commented_out_code_with_valid_reason' => 'Commented-out Code With Valid Reason',
            'commented_out_code_block_too_large' => 'Commented-out Code Block Too Large',
            'commented_out_code_requires_issue_reference' => 'Commented-out Code Requires Issue Reference',
            'commented_out_code_in_phpdoc_without_example_label' => 'PHPDoc Code Without Example Label',
            default => str_replace('_', ' ', ucfirst($type)),
        };
    }
}

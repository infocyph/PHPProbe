<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Comment\CommentFinding;
use Infocyph\PHPProbe\Comment\CommentScanner;
use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\SummaryJson;

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
        return CheckerRuntime::guarded(function () use ($args): int {
            $options = $this->parseArgs($args);

            if ($options['help']) {
                return $this->help();
            }

            $files = CheckerRuntime::phpFiles($options);
            $result = (new CommentScanner())->scan($files, $options);
            $failed = $this->shouldFail($result['findings'], $options['failOn']);
            $exitCode = $failed ? 1 : 0;

            $this->writeResult($result, $options, $failed);
            $this->writeSummaryJson($result, $options, $exitCode);

            return $exitCode;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOptions(): array
    {
        return [
            'help' => false,
            'format' => 'text',
            'strict' => false,
            'failOn' => 'error',
            'summaryJson' => '',
            'changedOnly' => false,
            'changedBase' => '',
            'config' => Paths::config('phpprobe.json'),
            'paths' => [],
            'excludes' => [],
            'policy' => 'standard',
            'suppressionEnabled' => true,
            'suppressionDirective' => '@phpprobe-ignore',
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
                'invalid_suppression_rule' => 'warning',
            ],
            'strictSeverity' => [
                'commented_out_code_without_reason' => 'error',
                'commented_out_code_without_valid_tag' => 'error',
                'commented_out_code_without_valid_reason' => 'error',
                'commented_out_code_with_weak_reason' => 'error',
                'commented_out_code_block_too_large' => 'error',
                'invalid_suppression_rule' => 'error',
            ],
        ];
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe comments [options] [paths...]',
            '',
            'Options:',
            '  --config=FILE                    read PHPProbe checker settings',
            '  --preset=NAME                    apply preset: default, standard, ci, or strict',
            '  --exclude=PATH                   skip a path (repeatable)',
            '  --format=text|json|markdown|sarif output format (default: text)',
            '  --json                           alias for --format=json',
            '  --summary-json=FILE              write machine-readable run summary',
            '  --strict                         enforce strict policy severities',
            '  --policy=relaxed|standard|strict comment policy profile',
            '  --fail-on=error|warning|info     minimum severity level to fail',
            '  --changed-only                   scan only changed PHP files from Git diff',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --tags=TODO,FIXME,...            override marker tags',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function parseArgs(array $args): array
    {
        $options = $this->defaultOptions();
        $options['config'] = $this->cli->configPath($args, $options['config']);
        $config = $this->cli->mergeConfigWithPreset(PhpProbeConfig::fromFile($options['config']), $this->cli->presetName($args));
        $options = $config->applyCommentOptions($options);
        $configuredPaths = $options['paths'];
        $this->cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg),
            'Unknown option for comments command: %s',
        );

        $options = $this->applyPolicyPreset($options);

        return $options;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $options
     */
    private function parseCliOption(array $args, int &$index, array &$options, string $arg): bool
    {
        if ($arg === '--strict') {
            $options['strict'] = true;

            return true;
        }

        if ($this->cli->parseCommonCheckerOptions($args, $index, $options, $arg, true)) {
            return true;
        }

        $policy = $this->cli->optionValue($arg, '--policy');

        if ($policy !== null) {
            $options['policy'] = strtolower(trim($policy));

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
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function applyPolicyPreset(array $options): array
    {
        return match ($options['policy']) {
            'relaxed' => [
                ...$options,
                'minReasonLength' => max(8, (int) $options['minReasonLength']),
                'maxAllowedBlockLines' => max(15, (int) $options['maxAllowedBlockLines']),
                'requireIssueForBlocksLongerThan' => max(5, (int) $options['requireIssueForBlocksLongerThan']),
            ],
            'standard' => $options,
            'strict' => [
                ...$options,
                'strict' => true,
                'allowOptionalReasonTagsInStrictMode' => false,
                'minReasonLength' => max(16, (int) $options['minReasonLength']),
                'maxAllowedBlockLines' => min(6, (int) $options['maxAllowedBlockLines']),
                'requireIssueForBlocksLongerThan' => min(2, (int) $options['requireIssueForBlocksLongerThan']),
            ],
            default => throw new \InvalidArgumentException(sprintf(
                'Invalid --policy value "%s". Expected: relaxed, standard, strict.',
                (string) $options['policy'],
            )),
        };
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
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{summaryJson:string,format:string,failOn:string} $options
     */
    private function writeSummaryJson(array $result, array $options, int $exitCode): void
    {
        if ($options['summaryJson'] === '') {
            return;
        }

        SummaryJson::write($options['summaryJson'], [
            'checker' => 'comments',
            'exit_code' => $exitCode,
            'fail_on' => $options['failOn'],
            'files' => $result['files'],
            'findings' => count($result['findings']),
            'format' => $options['format'],
            'suppressed_count' => $result['suppressed_count'],
        ]);
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{format:string,failOn:string} $options
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
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     */
    private function writeJson(array $result): void
    {
        fwrite(STDOUT, json_encode([
            'files' => $result['files'],
            'suppressed_count' => $result['suppressed_count'],
            'findings' => array_map(
                static fn(CommentFinding $finding): array => $finding->toArray(),
                $result['findings'],
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{failOn:string} $options
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        if ($result['findings'] === []) {
            fwrite(STDOUT, Ansi::color(sprintf('No comment policy findings (%d PHP files scanned).', $result['files']), 'green', STDOUT) . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

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

                fwrite(STDERR, sprintf(
                    '  %s  L%s  %s',
                    Ansi::severity($finding->severity, STDERR),
                    $lineLabel,
                    $this->findingTitle($finding->type),
                ) . PHP_EOL);
                fwrite(STDERR, '      ' . $finding->message . PHP_EOL);
            }
        }

        fwrite(STDERR, $this->summaryFooter($result, $options, $failed) . PHP_EOL);
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{failOn:string} $options
     */
    private function writeMarkdown(array $result, array $options, bool $failed): void
    {
        $lines = [
            '# PHPProbe Comment Report',
            '',
            sprintf('- Files scanned: `%d`', $result['files']),
            sprintf('- Findings: `%d`', count($result['findings'])),
            sprintf('- Suppressed: `%d`', $result['suppressed_count']),
            sprintf('- Fail-on: `%s`', $options['failOn']),
            sprintf('- Status: `%s`', $failed ? 'FAIL' : 'PASS'),
            '',
        ];

        if ($result['findings'] === []) {
            $lines[] = 'No comment policy findings.';
        } else {
            $lines[] = '| Severity | Type | Location | Message |';
            $lines[] = '| --- | --- | --- | --- |';

            foreach ($result['findings'] as $finding) {
                $lines[] = sprintf(
                    '| %s | `%s` | `%s:%d` | %s |',
                    strtoupper($finding->severity),
                    $finding->type,
                    $finding->file,
                    $finding->line,
                    str_replace('|', '\|', $finding->message),
                );
            }
        }

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     */
    private function writeSarif(array $result): void
    {
        $results = [];

        foreach ($result['findings'] as $finding) {
            $results[] = [
                'ruleId' => $finding->type,
                'level' => $this->sarifLevel($finding->severity),
                'message' => ['text' => $finding->message],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $finding->file],
                        'region' => ['startLine' => $finding->line],
                    ],
                ]],
            ];
        }

        fwrite(STDOUT, json_encode(Sarif::payload($results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function sarifLevel(string $severity): string
    {
        return match (strtolower($severity)) {
            'error', 'critical', 'high' => 'error',
            'warning', 'medium' => 'warning',
            default => 'note',
        };
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{failOn:string} $options
     */
    private function summaryFooter(array $result, array $options, bool $failed): string
    {
        return sprintf(
            'Summary: files=%d findings=%d suppressed=%d fail-on=%s status=%s',
            $result['files'],
            count($result['findings']),
            $result['suppressed_count'],
            $options['failOn'],
            $failed ? 'FAIL' : 'PASS',
        );
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
            'invalid_suppression_rule' => 'Invalid Suppression Rule',
            default => str_replace('_', ' ', ucfirst($type)),
        };
    }
}

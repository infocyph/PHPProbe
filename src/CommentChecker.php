<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Comment\CommentFinding;
use Infocyph\PHPProbe\Comment\CommentScanner;
use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Util\BaselineJson;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\ScopedTempFile;
use Infocyph\PHPProbe\Util\SummaryJson;

final readonly class CommentChecker
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

            return $this->runCheck($options);
        });
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

    private function confidenceRank(string $confidence): int
    {
        return match (strtolower($confidence)) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }

    private function defaultDocCacheFile(): string
    {
        return ScopedTempFile::forProject('.phpprobe-comments-doc-cache.json', '.phpprobe-comments-doc-cache');
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
            'failConfidence' => 'low',
            'emitMinSeverity' => 'info',
            'summaryJson' => '',
            'changedOnly' => false,
            'changedBase' => '',
            'config' => Paths::config('phpprobe.json'),
            'paths' => [],
            'excludes' => [],
            'policy' => 'standard',
            'docMode' => 'hybrid',
            'explain' => false,
            'baseline' => '',
            'writeBaseline' => '',
            'docCacheEnabled' => true,
            'docCacheFile' => $this->defaultDocCacheFile(),
            'docSignatureConsistency' => true,
            'docTypeHygiene' => true,
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
                'expired_suppression_rule' => 'warning',
                'dead_suppression_rule' => 'warning',
                'phpdoc_signature_mismatch' => 'warning',
                'phpdoc_unknown_param' => 'warning',
                'phpdoc_missing_param' => 'info',
                'phpdoc_invalid_tag_value' => 'warning',
            ],
            'strictSeverity' => [
                'commented_out_code_without_reason' => 'error',
                'commented_out_code_without_valid_tag' => 'error',
                'commented_out_code_without_valid_reason' => 'error',
                'commented_out_code_with_weak_reason' => 'error',
                'commented_out_code_block_too_large' => 'error',
                'invalid_suppression_rule' => 'error',
                'expired_suppression_rule' => 'error',
                'dead_suppression_rule' => 'error',
                'phpdoc_signature_mismatch' => 'error',
                'phpdoc_unknown_param' => 'error',
                'phpdoc_invalid_tag_value' => 'error',
            ],
            'ruleEnabled' => [],
            'ruleSeverity' => [],
            'customRules' => [],
        ];
    }

    private function findingFingerprint(CommentFinding $finding): string
    {
        return hash('sha256', json_encode([
            'type' => $finding->type,
            'file' => $finding->file,
            'line' => $finding->line,
            'end_line' => $finding->endLine,
            'subtype' => $finding->subtype,
            'message' => $finding->message,
        ], JSON_UNESCAPED_SLASHES) ?: '');
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
            'expired_suppression_rule' => 'Expired Suppression Rule',
            'dead_suppression_rule' => 'Unused Suppression Rule',
            'phpdoc_signature_mismatch' => 'PHPDoc Signature Mismatch',
            'phpdoc_unknown_param' => 'PHPDoc Unknown Parameter',
            'phpdoc_missing_param' => 'PHPDoc Missing Parameter',
            'phpdoc_invalid_tag_value' => 'Invalid PHPDoc Tag Value',
            default => str_replace('_', ' ', ucfirst($type)),
        };
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array<string, mixed> $options
     */
    private function finishRun(array $result, array $options): int
    {
        $failed = $this->shouldFail($result['findings'], $options['failOn'], $options['failConfidence']);
        $baselineWrite = $options['writeBaseline'] !== '';

        if ($baselineWrite) {
            $this->writeResult($result, $options, $failed);
            $this->writeSummaryJson($result, $options, 0);

            return 0;
        }

        $exitCode = $failed ? 1 : 0;
        $this->writeResult($result, $options, $failed);
        $this->writeSummaryJson($result, $options, $exitCode);

        return $exitCode;
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
            '  --format=text|json|markdown|sarif|github output format (default: text)',
            '  --json                           alias for --format=json',
            '  --summary-json=FILE              write machine-readable run summary',
            '  --strict                         enforce strict policy severities',
            '  --policy=relaxed|standard|strict comment policy profile',
            '  --doc-mode=heuristic|parser|hybrid doc-comment analysis mode (default: hybrid)',
            '  --baseline=FILE                  suppress findings already present in a baseline',
            '  --write-baseline[=FILE]          write current findings to a baseline and exit 0',
            '  --fail-on=error|warning|info     minimum severity level to fail',
            '  --fail-confidence=low|medium|high minimum confidence level to fail',
            '  --ci                             emit only error-level findings (fail-on=error)',
            '  --explain                        include finding explanations and suggestions',
            '  --changed-only                   scan only changed PHP files from Git diff',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --tags=TODO,FIXME,...            override marker tags',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @return array<string, true>
     */
    private function knownFindingFingerprints(string $baselinePath): array
    {
        return BaselineJson::knownFingerprints($baselinePath, 'Comment', 'findings');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeCommentOptions(array $options): array
    {
        $docMode = strtolower(trim((string) ($options['docMode'] ?? 'hybrid')));

        if (!in_array($docMode, ['heuristic', 'parser', 'hybrid'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid doc mode "%s". Expected: heuristic, parser, hybrid.',
                (string) ($options['docMode'] ?? ''),
            ));
        }

        $failConfidence = strtolower(trim((string) ($options['failConfidence'] ?? 'low')));

        if (!in_array($failConfidence, ['low', 'medium', 'high'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid fail confidence "%s". Expected: low, medium, high.',
                (string) ($options['failConfidence'] ?? ''),
            ));
        }

        $options['docMode'] = $docMode;
        $options['failConfidence'] = $failConfidence;

        return $options;
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

        return $this->normalizeCommentOptions($options);
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

        if ($this->cli->parseEnumOption(
            $options,
            $arg,
            '--policy',
            'policy',
            ['relaxed', 'standard', 'strict'],
            'Invalid --policy value "%s". Expected: relaxed, standard, strict.',
        )) {
            return true;
        }

        if ($this->cli->parseEnumOption(
            $options,
            $arg,
            '--doc-mode',
            'docMode',
            ['heuristic', 'parser', 'hybrid'],
            'Invalid --doc-mode value "%s". Expected: heuristic, parser, hybrid.',
        )) {
            return true;
        }

        if ($this->cli->parseEnumOption(
            $options,
            $arg,
            '--fail-confidence',
            'failConfidence',
            ['low', 'medium', 'high'],
            'Invalid --fail-confidence value "%s". Expected: low, medium, high.',
        )) {
            return true;
        }

        if ($arg === '--ci') {
            $options['failOn'] = 'error';
            $options['emitMinSeverity'] = 'error';

            return true;
        }

        if ($arg === '--explain') {
            $options['explain'] = true;

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

        return $this->cli->parseSnapshotFileOptions($options, $arg, '.phpprobe-comments-baseline.json');
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array<string, mixed> $options
     * @return array{files:int,findings:list<CommentFinding>,suppressed_count:int}
     */
    private function resultForOutput(array $result, array $options): array
    {
        $emitMinSeverity = strtolower(trim((string) ($options['emitMinSeverity'] ?? 'info')));

        $threshold = match ($emitMinSeverity) {
            'error' => 6,
            'warning' => 4,
            default => 1,
        };

        if ($threshold <= 1) {
            return $result;
        }

        $filtered = array_values(array_filter(
            $result['findings'],
            fn(CommentFinding $finding): bool => $this->severityRank($finding->severity) >= $threshold,
        ));

        return [
            ...$result,
            'findings' => $filtered,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCheck(array $options): int
    {
        ['result' => $result, 'raw_findings' => $rawFindings] = $this->scanFindings($options);
        $effectiveResult = $options['baseline'] !== ''
            ? $this->withoutBaselineFindings($result, $options['baseline'])
            : $result;
        $this->writeBaselineIfRequested($options, $rawFindings);

        return $this->finishRun($effectiveResult, $options);
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
     * @param array<string, mixed> $options
     * @return array{result:array{files:int,findings:list<CommentFinding>,suppressed_count:int},raw_findings:list<CommentFinding>}
     */
    private function scanFindings(array $options): array
    {
        $result = (new CommentScanner())->scan(CheckerRuntime::phpFiles($options), $options);

        return ['result' => $result, 'raw_findings' => $result['findings']];
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
     * @param list<CommentFinding> $findings
     */
    private function shouldFail(array $findings, string $failOn, string $failConfidence): bool
    {
        $severityThreshold = match ($failOn) {
            'info' => 1,
            'warning' => 4,
            default => 6,
        };
        $confidenceThreshold = $this->confidenceRank($failConfidence);

        foreach ($findings as $finding) {
            if ($this->severityRank($finding->severity) >= $severityThreshold
                && $this->confidenceRank($finding->confidence) >= $confidenceThreshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{summaryJson:string,format:string,failOn:string,failConfidence:string,docMode:string,explain:bool} $options
     * @return array<string, mixed>
     */
    private function summaryDetails(array $result, array $options): array
    {
        return [
            'fail_confidence' => $options['failConfidence'],
            'has_baseline' => $options['baseline'] !== '',
            'wrote_baseline' => $options['writeBaseline'] !== '',
            'files' => $result['files'],
            'findings' => count($result['findings']),
            'format' => $options['format'],
            'suppressed_count' => $result['suppressed_count'],
            'doc_mode' => $options['docMode'],
            'explain' => $options['explain'],
        ];
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{failOn:string,failConfidence:string,docMode:string,explain:bool} $options
     */
    private function summaryFooter(array $result, array $options, bool $failed): string
    {
        return sprintf(
            'Summary: files=%d findings=%d suppressed=%d fail-on=%s fail-confidence=%s doc-mode=%s baseline=%s status=%s',
            $result['files'],
            count($result['findings']),
            $result['suppressed_count'],
            $options['failOn'],
            $options['failConfidence'],
            $options['docMode'],
            $options['baseline'] !== '' ? 'on' : 'off',
            $failed ? 'FAIL' : 'PASS',
        );
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @return array{files:int,findings:list<CommentFinding>,suppressed_count:int}
     */
    private function withoutBaselineFindings(array $result, string $baselinePath): array
    {
        $known = $this->knownFindingFingerprints($baselinePath);

        if ($known === []) {
            return $result;
        }

        $filtered = array_values(array_filter(
            $result['findings'],
            fn(CommentFinding $finding): bool => !isset($known[$this->findingFingerprint($finding)]),
        ));

        $suppressedByBaseline = count($result['findings']) - count($filtered);

        return [
            ...$result,
            'findings' => $filtered,
            'suppressed_count' => $result['suppressed_count'] + $suppressedByBaseline,
        ];
    }

    /**
     * @param list<CommentFinding> $findings
     */
    private function writeBaseline(array $findings, string $path): void
    {
        $payload = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'findings' => array_map(fn(CommentFinding $finding): array => [
                'fingerprint' => $this->findingFingerprint($finding),
                'type' => $finding->type,
                'file' => $finding->file,
                'line' => $finding->line,
                'end_line' => $finding->endLine,
                'severity' => $finding->severity,
                'confidence' => $finding->confidence,
                'subtype' => $finding->subtype,
                'message' => $finding->message,
            ], $findings),
        ];

        BaselineJson::writeObject($path, $payload, 'comment');
    }

    /**
     * @param array<string, mixed> $options
     * @param list<CommentFinding> $rawFindings
     */
    private function writeBaselineIfRequested(array $options, array $rawFindings): void
    {
        if ($options['writeBaseline'] !== '') {
            $this->writeBaseline($rawFindings, $options['writeBaseline']);
        }
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     */
    private function writeGithub(array $result): void
    {
        foreach ($result['findings'] as $finding) {
            $level = match (strtolower($finding->severity)) {
                'error', 'critical', 'high' => 'error',
                'warning', 'medium' => 'warning',
                default => 'notice',
            };

            fwrite(STDOUT, GithubAnnotation::emit(
                $level,
                'PHPProbe comments',
                sprintf('%s (%s)', $finding->message, $finding->type),
                $finding->file,
                $finding->line,
            ) . PHP_EOL);
        }

        if ($result['findings'] === []) {
            fwrite(STDOUT, GithubAnnotation::emit('notice', 'PHPProbe comments', 'No comment policy findings.') . PHP_EOL);
        }
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
                static function (CommentFinding $finding): array {
                    $payload = $finding->toArray();

                    if ($finding->suggestion !== null && trim($finding->suggestion) !== '') {
                        $payload['autofix'] = [
                            'kind' => 'suggestion',
                            'text' => $finding->suggestion,
                            'rule' => $finding->type,
                        ];
                    }

                    return $payload;
                },
                $result['findings'],
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{failOn:string,failConfidence:string,docMode:string,explain:bool} $options
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
            sprintf('- Fail-confidence: `%s`', $options['failConfidence']),
            sprintf('- Doc mode: `%s`', $options['docMode']),
            sprintf('- Baseline: `%s`', $options['baseline'] !== '' ? $options['baseline'] : '(none)'),
            sprintf('- Status: `%s`', $failed ? 'FAIL' : 'PASS'),
            '',
        ];

        if ($result['findings'] === []) {
            $lines[] = 'No comment policy findings.';
        } else {
            $lines[] = '| Severity | Confidence | Type | Subtype | Location | Message |';
            $lines[] = '| --- | --- | --- | --- | --- | --- |';

            foreach ($result['findings'] as $finding) {
                $lines[] = sprintf(
                    '| %s | %s | `%s` | `%s` | `%s:%d` | %s |',
                    strtoupper($finding->severity),
                    strtoupper($finding->confidence),
                    $finding->type,
                    $finding->subtype ?? '-',
                    $finding->file,
                    $finding->line,
                    str_replace('|', '\|', $finding->message),
                );

                if ($options['explain'] && $finding->explanation !== null && trim($finding->explanation) !== '') {
                    $lines[] = sprintf('|  |  |  |  |  | Why: %s |', str_replace('|', '\|', $finding->explanation));
                }

                if ($options['explain'] && $finding->suggestion !== null && trim($finding->suggestion) !== '') {
                    $lines[] = sprintf('|  |  |  |  |  | Suggestion: %s |', str_replace('|', '\|', $finding->suggestion));
                }
            }
        }

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{format:string,failOn:string,failConfidence:string,docMode:string,explain:bool} $options
     */
    private function writeResult(array $result, array $options, bool $failed): void
    {
        $result = $this->resultForOutput($result, $options);

        match ($options['format']) {
            'json' => $this->writeJson($result),
            'markdown' => $this->writeMarkdown($result, $options, $failed),
            'sarif' => $this->writeSarif($result),
            'github' => $this->writeGithub($result),
            default => $this->writeText($result, $options, $failed),
        };
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     */
    private function writeSarif(array $result): void
    {
        $results = [];

        foreach ($result['findings'] as $finding) {
            $properties = [
                'severity' => $finding->severity,
                'confidence' => $finding->confidence,
            ];

            if ($finding->subtype !== null) {
                $properties['subtype'] = $finding->subtype;
            }

            if ($finding->tag !== null) {
                $properties['tag'] = $finding->tag;
            }

            if ($finding->scope !== null) {
                $properties['scope'] = $finding->scope;
            }

            if ($finding->reason !== null) {
                $properties['reason'] = $finding->reason;
            }

            if ($finding->suggestion !== null) {
                $properties['suggestion'] = $finding->suggestion;
            }

            $results[] = [
                'ruleId' => $finding->type,
                'level' => $this->sarifLevel($finding->severity),
                'message' => ['text' => $finding->message],
                'properties' => $properties,
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

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{summaryJson:string,format:string,failOn:string,failConfidence:string,docMode:string,explain:bool} $options
     */
    private function writeSummaryJson(array $result, array $options, int $exitCode): void
    {
        SummaryJson::writeCheckerSummary(
            $options['summaryJson'],
            'comments',
            $exitCode,
            $options['failOn'],
            $this->summaryDetails($result, $options),
        );
    }

    /**
     * @param array{files:int,findings:list<CommentFinding>,suppressed_count:int} $result
     * @param array{failOn:string,failConfidence:string,docMode:string,explain:bool} $options
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        if ($options['writeBaseline'] !== '') {
            fwrite(STDOUT, Ansi::color(sprintf('Comment baseline written: %s', $options['writeBaseline']), 'cyan', STDOUT) . PHP_EOL);
        }

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
                $confidence = strtoupper($finding->confidence);
                $subtype = $finding->subtype !== null ? sprintf(' [%s]', $finding->subtype) : '';

                fwrite(STDERR, sprintf(
                    '  %s  L%s  %s (%s)%s',
                    Ansi::severity($finding->severity, STDERR),
                    $lineLabel,
                    $this->findingTitle($finding->type),
                    $confidence,
                    $subtype,
                ) . PHP_EOL);
                fwrite(STDERR, '      ' . $finding->message . PHP_EOL);

                if ($options['explain'] && $finding->explanation !== null && trim($finding->explanation) !== '') {
                    fwrite(STDERR, '      Why: ' . $finding->explanation . PHP_EOL);
                }

                if ($options['explain'] && $finding->suggestion !== null && trim($finding->suggestion) !== '') {
                    fwrite(STDERR, '      Suggestion: ' . $finding->suggestion . PHP_EOL);
                }
            }
        }

        fwrite(STDERR, $this->summaryFooter($result, $options, $failed) . PHP_EOL);
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Comment\CommentFinding;
use Infocyph\PHPProbe\Comment\CommentScanner;
use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Infocyph\PHPProbe\Console\Ansi;
use Infocyph\PHPProbe\Console\CliTable;
use Infocyph\PHPProbe\Util\BaselineJson;
use Infocyph\PHPProbe\Util\CheckerRuntime;
use Infocyph\PHPProbe\Util\GithubAnnotation;
use Infocyph\PHPProbe\Util\InputFileGroups;
use Infocyph\PHPProbe\Util\ProjectPath;
use Infocyph\PHPProbe\Util\Sarif;
use Infocyph\PHPProbe\Util\ScopedTempFile;
use Infocyph\PHPProbe\Util\SummaryJson;

/**
 * @phpstan-type CustomRule array{id:string,pattern:string,severity:string,message:string,enabled:bool,scope:string}
 * @phpstan-type CommentGroup array{name:string,files:int,findings:int,paths:list<string>}
 * @phpstan-type CommentScanResult array{files:int,findings:list<CommentFinding>,suppressed_count:int}
 * @phpstan-type CommentResult array{files:int,findings:list<CommentFinding>,suppressed_count:int,groups:list<CommentGroup>}
 * @phpstan-type CommentOptions array{
 *     help:bool, format:string, color:string, strict:bool,
 *     failOn:string, failConfidence:string, summaryJson:string,
 *     changedOnly:bool, changedBase:string,
 *     textColorSuccess:string, textColorError:string, textColorInfo:string, textColorFile:string,
 *     severityColors:array<string,string>, config:string, paths:list<string>, excludes:list<string>,
 *     policy:string, docMode:string, explain:bool, baseline:string, writeBaseline:string,
 *     docCacheEnabled:bool, docCacheFile:string, docSignatureConsistency:bool, docTypeHygiene:bool,
 *     suppressionEnabled:bool, suppressionDirective:string,
 *     scanMarkers:bool, markerTags:list<string>, markerSeverity:array<string,string>,
 *     commentedOutEnabled:bool, allowedReasonTags:list<string>, optionalReasonTags:list<string>,
 *     allowOptionalReasonTagsInStrictMode:bool, minReasonLength:int, maxAllowedBlockLines:int,
 *     requireIssueForBlocksLongerThan:int, allowedIssuePatterns:list<string>,
 *     allowBlankLineBetweenReasonAndCode:bool, allowReasonBeforeBlockComment:bool,
 *     allowBlankLineBetweenReasonAndCodeInBlock:bool, allowPhpdocExamples:bool,
 *     applyLimitsToPhpdoc:bool, phpdocExampleLabels:list<string>,
 *     typeSeverity:array<string,string>, strictSeverity:array<string,string>,
 *     ruleEnabled:array<string,bool>, ruleSeverity:array<string,string>, customRules:list<CustomRule>
 * }
 */
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
     * @param CommentOptions $options
     * @return CommentOptions
     */
    private function applyPolicyPreset(array $options): array
    {
        return match ($options['policy']) {
            'relaxed' => [
                ...$options,
                'minReasonLength' => max(8, $options['minReasonLength']),
                'maxAllowedBlockLines' => max(15, $options['maxAllowedBlockLines']),
                'requireIssueForBlocksLongerThan' => max(5, $options['requireIssueForBlocksLongerThan']),
            ],
            'standard' => $options,
            'strict' => [
                ...$options,
                'strict' => true,
                'minReasonLength' => max(24, $options['minReasonLength']),
                'maxAllowedBlockLines' => min(6, $options['maxAllowedBlockLines']),
                'requireIssueForBlocksLongerThan' => min(4, $options['requireIssueForBlocksLongerThan']),
            ],
            default => throw new \InvalidArgumentException(sprintf(
                'Invalid --policy value "%s". Expected: relaxed, standard, strict.',
                $options['policy'],
            )),
        };
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, bool> $default
     * @return array<string, bool>
     */
    private function boolMapOption(array $options, string $key, array $default): array
    {
        $normalized = $this->typedMapOption($options, $key, 'bool');

        return $normalized !== null
            ? array_map(static fn(bool|string $value): bool => $value === true, $normalized)
            : $default;
    }

    /** @param array<string, mixed> $options */
    private function boolOption(array $options, string $key, bool $default): bool
    {
        $value = $this->scalarOption($options, $key);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param list<CommentGroup> $groups
     * @return array<string, list<string>>
     */
    private function commentGroupPaths(array $groups): array
    {
        $paths = [];

        foreach ($groups as $group) {
            $paths[$group['name']] = $group['paths'];
        }

        return $paths;
    }

    /**
     * @param array<string, list<string>> $groups
     * @param list<CommentFinding> $findings
     * @return list<CommentGroup>
     */
    private function commentGroupSummaries(array $groups, array $findings): array
    {
        $summaries = [];

        foreach ($groups as $name => $files) {
            $relativeFiles = array_map(ProjectPath::relative(...), $files);
            $lookup = array_fill_keys($relativeFiles, true);
            $summaries[] = [
                'name' => $name,
                'files' => count($files),
                'findings' => count(array_filter(
                    $findings,
                    static fn(CommentFinding $finding): bool => isset($lookup[$finding->file]),
                )),
                'paths' => $relativeFiles,
            ];
        }

        return $summaries;
    }

    private function confidenceRank(string $confidence): int
    {
        return match (strtolower($confidence)) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }

    /**
     * @param list<string> $args
     * @return CommentOptions
     */
    private function configuredOptions(array $args): array
    {
        $options = $this->defaultOptions();
        $options['config'] = $this->cli->configPath($args, $options['config']);
        $config = PhpProbeConfig::fromFile($options['config']);
        $config = $this->cli->mergeConfigWithPreset($config, $this->cli->presetName($args));

        return $this->normalizeCommentOptions($config->applyCommentOptions($options));
    }

    /** @return list<CustomRule> */
    private function customRulesOption(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $rule) {
            if (!is_array($rule)
                || !is_string($rule['id'] ?? null)
                || !is_string($rule['pattern'] ?? null)) {
                continue;
            }

            $id = trim($rule['id']);
            if ($id === '' || trim($rule['pattern']) === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'pattern' => $rule['pattern'],
                'severity' => is_string($rule['severity'] ?? null) ? $rule['severity'] : 'warning',
                'message' => is_string($rule['message'] ?? null) && trim($rule['message']) !== ''
                    ? $rule['message']
                    : sprintf('Matched custom comment rule "%s".', $id),
                'enabled' => ($rule['enabled'] ?? true) === true,
                'scope' => is_string($rule['scope'] ?? null) ? $rule['scope'] : 'all',
            ];
        }

        return $normalized;
    }

    private function defaultDocCacheFile(): string
    {
        return ScopedTempFile::forProject('.phpprobe-comments-doc-cache.json', '.phpprobe-comments-doc-cache');
    }

    /**
     * @return CommentOptions
     */
    private function defaultOptions(): array
    {
        return [
            'help' => false,
            'format' => 'text',
            'color' => 'auto',
            'strict' => false,
            'failOn' => 'error',
            'failConfidence' => 'low',
            'summaryJson' => '',
            'changedOnly' => false,
            'changedBase' => '',
            'textColorSuccess' => 'green',
            'textColorError' => 'red',
            'textColorInfo' => 'cyan',
            'textColorFile' => 'cyan',
            'severityColors' => [],
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
            'minReasonLength' => 16,
            'maxAllowedBlockLines' => 10,
            'requireIssueForBlocksLongerThan' => 6,
            'allowedIssuePatterns' => ['/#\d+/', '/[A-Z][A-Z0-9]+-\d+/'],
            'allowBlankLineBetweenReasonAndCode' => false,
            'allowReasonBeforeBlockComment' => true,
            'allowBlankLineBetweenReasonAndCodeInBlock' => true,
            'allowPhpdocExamples' => true,
            'applyLimitsToPhpdoc' => false,
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
     * @param CommentResult $result
     * @param CommentOptions $options
     */
    private function finishRun(array $result, array $options): int
    {
        $failed = $this->shouldFail($result['findings'], $options['failOn'], $options['failConfidence']);
        $exitCode = $options['writeBaseline'] === '' && $failed ? 1 : 0;
        $this->writeRunOutputs($this->resultForOutput($result, $options), $options, $failed, $exitCode);

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
            '  --format=text|json|phpstan-json|markdown|sarif|github output format (default: text)',
            '  --color=auto|always|never       ANSI color mode (default: auto)',
            '  --json                           alias for --format=json',
            '  --summary-json=FILE              write machine-readable run summary',
            '  --strict                         enforce strict policy severities',
            '  --policy=relaxed|standard|strict comment policy profile',
            '  --doc-mode=heuristic|parser|hybrid doc-comment analysis mode (default: hybrid)',
            '  --baseline=FILE                  suppress findings already present in a baseline',
            '  --write-baseline[=FILE]          write current findings to a baseline and exit 0',
            '  --fail-on=error|warning|info     minimum severity level to emit and fail',
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

    /** @param array<string, mixed> $options */
    private function intOption(array $options, string $key, int $default): int
    {
        $value = $this->scalarOption($options, $key);

        return is_int($value) ? $value : $default;
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
     * @return CommentOptions
     */
    private function normalizeCommentOptions(array $options): array
    {
        $defaults = $this->defaultOptions();
        $docMode = strtolower(trim($this->stringOption($options, 'docMode', $defaults['docMode'])));

        if (!in_array($docMode, ['heuristic', 'parser', 'hybrid'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid doc mode "%s". Expected: heuristic, parser, hybrid.',
                $this->stringOption($options, 'docMode', ''),
            ));
        }

        $failConfidence = strtolower(trim($this->stringOption($options, 'failConfidence', $defaults['failConfidence'])));

        if (!in_array($failConfidence, ['low', 'medium', 'high'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid fail confidence "%s". Expected: low, medium, high.',
                $this->stringOption($options, 'failConfidence', ''),
            ));
        }

        return [
            'help' => $this->boolOption($options, 'help', $defaults['help']),
            'format' => $this->stringOption($options, 'format', $defaults['format']),
            'color' => $this->stringOption($options, 'color', $defaults['color']),
            'strict' => $this->boolOption($options, 'strict', $defaults['strict']),
            'failOn' => $this->stringOption($options, 'failOn', $defaults['failOn']),
            'failConfidence' => $failConfidence,
            'summaryJson' => $this->stringOption($options, 'summaryJson', $defaults['summaryJson']),
            'changedOnly' => $this->boolOption($options, 'changedOnly', $defaults['changedOnly']),
            'changedBase' => $this->stringOption($options, 'changedBase', $defaults['changedBase']),
            'textColorSuccess' => $this->stringOption($options, 'textColorSuccess', $defaults['textColorSuccess']),
            'textColorError' => $this->stringOption($options, 'textColorError', $defaults['textColorError']),
            'textColorInfo' => $this->stringOption($options, 'textColorInfo', $defaults['textColorInfo']),
            'textColorFile' => $this->stringOption($options, 'textColorFile', $defaults['textColorFile']),
            'severityColors' => $this->stringMapOption($options, 'severityColors', $defaults['severityColors']),
            'config' => $this->stringOption($options, 'config', $defaults['config']),
            'paths' => $this->stringListOption($options, 'paths', $defaults['paths']),
            'excludes' => $this->stringListOption($options, 'excludes', $defaults['excludes']),
            'policy' => $this->stringOption($options, 'policy', $defaults['policy']),
            'docMode' => $docMode,
            'explain' => $this->boolOption($options, 'explain', $defaults['explain']),
            'baseline' => $this->stringOption($options, 'baseline', $defaults['baseline']),
            'writeBaseline' => $this->stringOption($options, 'writeBaseline', $defaults['writeBaseline']),
            'docCacheEnabled' => $this->boolOption($options, 'docCacheEnabled', $defaults['docCacheEnabled']),
            'docCacheFile' => $this->stringOption($options, 'docCacheFile', $defaults['docCacheFile']),
            'docSignatureConsistency' => $this->boolOption($options, 'docSignatureConsistency', $defaults['docSignatureConsistency']),
            'docTypeHygiene' => $this->boolOption($options, 'docTypeHygiene', $defaults['docTypeHygiene']),
            'suppressionEnabled' => $this->boolOption($options, 'suppressionEnabled', $defaults['suppressionEnabled']),
            'suppressionDirective' => $this->stringOption($options, 'suppressionDirective', $defaults['suppressionDirective']),
            'scanMarkers' => $this->boolOption($options, 'scanMarkers', $defaults['scanMarkers']),
            'markerTags' => $this->stringListOption($options, 'markerTags', $defaults['markerTags']),
            'markerSeverity' => $this->stringMapOption($options, 'markerSeverity', $defaults['markerSeverity']),
            'commentedOutEnabled' => $this->boolOption($options, 'commentedOutEnabled', $defaults['commentedOutEnabled']),
            'allowedReasonTags' => $this->stringListOption($options, 'allowedReasonTags', $defaults['allowedReasonTags']),
            'optionalReasonTags' => $this->stringListOption($options, 'optionalReasonTags', $defaults['optionalReasonTags']),
            'allowOptionalReasonTagsInStrictMode' => $this->boolOption($options, 'allowOptionalReasonTagsInStrictMode', $defaults['allowOptionalReasonTagsInStrictMode']),
            'minReasonLength' => $this->intOption($options, 'minReasonLength', $defaults['minReasonLength']),
            'maxAllowedBlockLines' => $this->intOption($options, 'maxAllowedBlockLines', $defaults['maxAllowedBlockLines']),
            'requireIssueForBlocksLongerThan' => $this->intOption($options, 'requireIssueForBlocksLongerThan', $defaults['requireIssueForBlocksLongerThan']),
            'allowedIssuePatterns' => $this->stringListOption($options, 'allowedIssuePatterns', $defaults['allowedIssuePatterns']),
            'allowBlankLineBetweenReasonAndCode' => $this->boolOption($options, 'allowBlankLineBetweenReasonAndCode', $defaults['allowBlankLineBetweenReasonAndCode']),
            'allowReasonBeforeBlockComment' => $this->boolOption($options, 'allowReasonBeforeBlockComment', $defaults['allowReasonBeforeBlockComment']),
            'allowBlankLineBetweenReasonAndCodeInBlock' => $this->boolOption($options, 'allowBlankLineBetweenReasonAndCodeInBlock', $defaults['allowBlankLineBetweenReasonAndCodeInBlock']),
            'allowPhpdocExamples' => $this->boolOption($options, 'allowPhpdocExamples', $defaults['allowPhpdocExamples']),
            'applyLimitsToPhpdoc' => $this->boolOption($options, 'applyLimitsToPhpdoc', $defaults['applyLimitsToPhpdoc']),
            'phpdocExampleLabels' => $this->stringListOption($options, 'phpdocExampleLabels', $defaults['phpdocExampleLabels']),
            'typeSeverity' => $this->stringMapOption($options, 'typeSeverity', $defaults['typeSeverity']),
            'strictSeverity' => $this->stringMapOption($options, 'strictSeverity', $defaults['strictSeverity']),
            'ruleEnabled' => $this->boolMapOption($options, 'ruleEnabled', $defaults['ruleEnabled']),
            'ruleSeverity' => $this->stringMapOption($options, 'ruleSeverity', $defaults['ruleSeverity']),
            'customRules' => $this->customRulesOption($options['customRules'] ?? null),
        ];
    }

    /**
     * @param list<string> $args
     * @return CommentOptions
     */
    private function parseArgs(array $args): array
    {
        $options = $this->configuredOptions($args);
        $configuredPaths = $options['paths'];
        $this->cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg),
            'Unknown option for comments command: %s',
        );

        $options = $this->normalizeCommentOptions($options);
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
     * @param CommentResult $result
     * @param CommentOptions $options
     * @return CommentResult
     */
    private function resultForOutput(array $result, array $options): array
    {
        $threshold = $this->severityThreshold($options['failOn']);

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
            'groups' => $this->commentGroupSummaries(
                $this->commentGroupPaths($result['groups']),
                $filtered,
            ),
        ];
    }

    /**
     * @param CommentOptions $options
     */
    private function runCheck(array $options): int
    {
        CheckerRuntime::applyColorMode($options);

        ['result' => $result, 'raw_findings' => $rawFindings, 'groups' => $groups] = $this->scanFindings($options);
        $effectiveResult = $options['baseline'] !== ''
            ? $this->withoutBaselineFindings($result, $options['baseline'])
            : $result;
        $effectiveResult['groups'] = $this->commentGroupSummaries($groups, $effectiveResult['findings']);
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

    /** @param array<string, mixed> $options */
    private function scalarOption(array $options, string $key): bool|int|string|null
    {
        $value = $options[$key] ?? null;

        return is_bool($value) || is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * @param CommentOptions $options
     * @return array{result:CommentScanResult,raw_findings:list<CommentFinding>,groups:array<string,list<string>>}
     */
    private function scanFindings(array $options): array
    {
        $files = CheckerRuntime::phpFiles($options);
        $groups = InputFileGroups::group($files, $options['paths']);
        $result = new CommentScanner()->scan(InputFileGroups::roundRobin($groups), $options);

        return ['result' => $result, 'raw_findings' => $result['findings'], 'groups' => $groups];
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

    private function severityThreshold(string $failOn): int
    {
        return match ($failOn) {
            'error' => 6,
            'warning' => 4,
            default => 1,
        };
    }

    /**
     * @param list<CommentFinding> $findings
     */
    private function shouldFail(array $findings, string $failOn, string $failConfidence): bool
    {
        $severityThreshold = $this->severityThreshold($failOn);
        $confidenceThreshold = $this->confidenceRank($failConfidence);

        return array_any($findings, fn($finding) => $this->severityRank($finding->severity) >= $severityThreshold
            && $this->confidenceRank($finding->confidence) >= $confidenceThreshold);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $default
     * @return list<string>
     */
    private function stringListOption(array $options, string $key, array $default): array
    {
        $value = $options[$key] ?? null;

        return is_array($value) && array_is_list($value) && count($value) === count(array_filter($value, is_string(...)))
            ? array_values(array_filter($value, is_string(...)))
            : $default;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $default
     * @return array<string, string>
     */
    private function stringMapOption(array $options, string $key, array $default): array
    {
        $normalized = $this->typedMapOption($options, $key, 'string');

        return $normalized !== null ? array_map(strval(...), $normalized) : $default;
    }

    /** @param array<string, mixed> $options */
    private function stringOption(array $options, string $key, string $default): string
    {
        $value = $this->scalarOption($options, $key);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param CommentResult $result
     * @param CommentOptions $options
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
            'groups' => count($result['groups']),
            'format' => $options['format'],
            'suppressed_count' => $result['suppressed_count'],
            'doc_mode' => $options['docMode'],
            'explain' => $options['explain'],
        ];
    }

    /**
     * @param CommentResult $result
     * @param CommentOptions $options
     */
    private function summaryFooter(array $result, array $options, bool $failed): string
    {
        return sprintf(
            'Summary: files=%d findings=%d groups=%d suppressed=%d fail-on=%s fail-confidence=%s doc-mode=%s baseline=%s status=%s',
            $result['files'],
            count($result['findings']),
            count($result['groups']),
            $result['suppressed_count'],
            $options['failOn'],
            $options['failConfidence'],
            $options['docMode'],
            $options['baseline'] !== '' ? 'on' : 'off',
            $failed ? 'FAIL' : 'PASS',
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool|string>|null
     */
    private function typedMapOption(array $options, string $key, string $type): ?array
    {
        $value = $options[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $mapKey => $mapValue) {
            $validValue = $type === 'bool' ? is_bool($mapValue) : is_string($mapValue);
            if (!is_string($mapKey) || !$validValue) {
                return null;
            }

            $normalized[$mapKey] = $mapValue;
        }

        return $normalized;
    }

    /**
     * @param CommentScanResult $result
     * @return CommentScanResult
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
     * @param CommentOptions $options
     * @param list<CommentFinding> $rawFindings
     */
    private function writeBaselineIfRequested(array $options, array $rawFindings): void
    {
        if ($options['writeBaseline'] !== '') {
            $this->writeBaseline($rawFindings, $options['writeBaseline']);
        }
    }

    /**
     * @param CommentResult $result
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
     * @param CommentResult $result
     */
    private function writeJson(array $result): void
    {
        fwrite(STDOUT, json_encode([
            'files' => $result['files'],
            'suppressed_count' => $result['suppressed_count'],
            'groups' => array_map(static fn(array $group): array => [
                'name' => $group['name'],
                'files' => $group['files'],
                'findings' => $group['findings'],
            ], $result['groups']),
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
     * @param CommentResult $result
     * @param CommentOptions $options
     */
    private function writeMarkdown(array $result, array $options, bool $failed): void
    {
        $lines = [
            '# PHPProbe Comment Report',
            '',
            sprintf('- Files scanned: `%d`', $result['files']),
            sprintf('- Findings: `%d`', count($result['findings'])),
            sprintf('- Input groups: `%d`', count($result['groups'])),
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
     * @param CommentResult $result
     */
    private function writePhpStanJson(array $result): void
    {
        /** @var array<string, array{errors:int,messages:list<array<string,bool|int|string>>}> $files */
        $files = [];

        foreach ($result['findings'] as $finding) {
            $files[$finding->file] ??= ['errors' => 0, 'messages' => []];
            $files[$finding->file]['errors']++;
            $message = [
                'message' => $finding->message,
                'line' => $finding->line,
                'ignorable' => true,
                'identifier' => $finding->type,
            ];

            if ($finding->suggestion !== null && trim($finding->suggestion) !== '') {
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

    /**
     * @param CommentResult $result
     * @param CommentOptions $options
     */
    private function writeResult(array $result, array $options, bool $failed): void
    {
        match ($options['format']) {
            'json' => $this->writeJson($result),
            'phpstan-json' => $this->writePhpStanJson($result),
            'markdown' => $this->writeMarkdown($result, $options, $failed),
            'sarif' => $this->writeSarif($result),
            'github' => $this->writeGithub($result),
            default => $this->writeText($result, $options, $failed),
        };
    }

    /**
     * @param CommentResult $result
     * @param CommentOptions $options
     */
    private function writeRunOutputs(array $result, array $options, bool $failed, int $exitCode): void
    {
        $this->writeResult($result, $options, $failed);
        $this->writeSummaryJson($result, $options, $exitCode);
    }

    /**
     * @param CommentResult $result
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
     * @param CommentResult $result
     * @param CommentOptions $options
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
     * @param CommentResult $result
     * @param CommentOptions $options
     */
    private function writeText(array $result, array $options, bool $failed): void
    {
        if ($options['writeBaseline'] !== '') {
            fwrite(STDOUT, Ansi::color(sprintf('Comment baseline written: %s', $options['writeBaseline']), (string) $options['textColorInfo'], STDOUT) . PHP_EOL);
        }

        $stream = $result['findings'] === [] ? STDOUT : STDERR;
        $groupRows = array_map(static fn(array $group): array => [
            $group['name'],
            $group['files'],
            $group['findings'],
        ], $result['groups']);
        fwrite($stream, 'Input groups:' . PHP_EOL);
        fwrite($stream, CliTable::render(['Group', 'Files', 'Findings'], $groupRows, [0 => 48]) . PHP_EOL);

        if ($result['findings'] === []) {
            fwrite(STDOUT, Ansi::color(sprintf('No comment policy findings (%d PHP files scanned).', $result['files']), (string) $options['textColorSuccess'], STDOUT) . PHP_EOL);
            fwrite(STDOUT, $this->summaryFooter($result, $options, $failed) . PHP_EOL);

            return;
        }

        $grouped = [];

        foreach ($result['findings'] as $finding) {
            $grouped[$finding->file][] = $finding;
        }

        $groupPaths = $this->commentGroupPaths($result['groups']);

        fwrite(
            STDERR,
            Ansi::color(
                sprintf(
                    'Comment policy findings: %d issue(s) in %d file(s) scanned.',
                    count($result['findings']),
                    count($grouped),
                ),
                (string) $options['textColorError'],
                STDERR,
            ) . PHP_EOL,
        );

        foreach ($grouped as $file => $findings) {
            $group = InputFileGroups::nameFor($file, $groupPaths);
            fwrite(STDERR, Ansi::color(sprintf('%s [%s]', $file, $group), (string) $options['textColorFile'], STDERR) . PHP_EOL);
            $rows = [];

            foreach ($findings as $finding) {
                $lineLabel = $finding->line === $finding->endLine
                    ? (string) $finding->line
                    : sprintf('%d-%d', $finding->line, $finding->endLine);
                $rule = $this->findingTitle($finding->type);

                if ($finding->subtype !== null) {
                    $rule .= sprintf(' [%s]', $finding->subtype);
                }

                $message = $finding->message;

                if ($options['explain'] && $finding->explanation !== null && trim($finding->explanation) !== '') {
                    $message .= ' Why: ' . $finding->explanation;
                }

                if ($options['explain'] && $finding->suggestion !== null && trim($finding->suggestion) !== '') {
                    $message .= ' Suggestion: ' . $finding->suggestion;
                }

                $rows[] = [
                    $lineLabel,
                    Ansi::severity($finding->severity, STDERR, $options['severityColors']),
                    strtoupper($finding->confidence),
                    $rule,
                    $message,
                ];
            }

            fwrite(STDERR, CliTable::render(
                ['Line', 'Severity', 'Confidence', 'Rule', 'Message'],
                $rows,
                [0 => 10, 1 => 10, 2 => 10, 3 => 36, 4 => 72],
            ) . PHP_EOL);
        }

        fwrite(STDERR, $this->summaryFooter($result, $options, $failed) . PHP_EOL);
    }
}

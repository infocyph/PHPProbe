<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

use Infocyph\PHPProbe\Util\ArrayShape;

final readonly class PhpProbeConfig
{
    /**
     * @param array<string, mixed> $config
     */
    private function __construct(private array $config) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            return new self([]);
        }

        $contents = file_get_contents($path);

        if (!is_string($contents) || $contents === '') {
            return new self([]);
        }

        $decoded = json_decode($contents, true);

        return new self(ArrayShape::stringKeyed($decoded));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyApiOptions(array $options): array
    {
        $bundle = $this->sectionBundle('api', true);
        $baseline = $this->apiBaselineValues($bundle['section']);
        $this->applyControlsFromBundle($options, $bundle);
        $this->applyApiBaselineValues($options, ...$baseline);

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyCommentOptions(array $options): array
    {
        $bundle = $this->sectionBundle('comments', true);
        $comments = $bundle['section'];
        $commentedOut = $this->section('commented_out_code');
        $scanMarkers = $this->boolValue($comments, 'scan_markers');
        $docMode = $this->stringValue($comments, 'doc_mode');
        $failConfidence = $this->stringValue($comments, 'fail_confidence');
        $explain = $this->boolValue($comments, 'explain');
        $docSignatureConsistency = $this->boolValue($comments, 'doc_signature_consistency');
        $docTypeHygiene = $this->boolValue($comments, 'doc_type_hygiene');
        $baseline = $this->stringValue($comments, 'baseline');
        $writeBaseline = $this->stringValue($comments, 'write_baseline');
        $markerTags = array_map(strtoupper(...), $this->stringList($this->value($comments, 'marker_tags')));
        $markerSeverity = $this->stringMap($this->value($comments, 'marker_severity'), true);
        $customRules = $this->customCommentRules($comments);
        $docCache = ArrayShape::stringKeyed($this->value($comments, 'doc_cache'));
        $docCacheEnabled = $this->boolValue($docCache, 'enabled');
        $docCacheFile = $this->stringValue($docCache, 'file');
        $commentedEnabled = $this->boolValue($commentedOut, 'enabled');
        $allowedReasonTags = array_map(strtoupper(...), $this->stringList($this->value($commentedOut, 'allowed_reason_tags')));
        $optionalReasonTags = array_map(strtoupper(...), $this->stringList($this->value($commentedOut, 'optional_reason_tags')));
        $ignorePaths = $this->stringList($this->value($commentedOut, 'ignore_paths'));
        $suppression = ArrayShape::stringKeyed($this->value($commentedOut, 'suppression'));
        $suppressionEnabled = $this->boolValue($suppression, 'enabled');
        $suppressionDirective = $this->stringValue($suppression, 'directive');
        $policy = $this->stringValue($commentedOut, 'policy');
        $allowOptionalInStrict = $this->boolValue($commentedOut, 'allow_optional_reason_tags_in_strict_mode');
        $minReasonLength = $this->intValue($commentedOut, 'min_reason_length');
        $maxBlockLines = $this->intValue($commentedOut, 'max_allowed_block_lines');
        $requireIssue = $this->intValue($commentedOut, 'require_issue_for_blocks_longer_than');
        $issuePatterns = $this->stringList($this->value($commentedOut, 'allowed_issue_patterns'));
        $singleLine = ArrayShape::stringKeyed($this->value($commentedOut, 'single_line_comments'));
        $block = ArrayShape::stringKeyed($this->value($commentedOut, 'block_comments'));
        $phpdoc = ArrayShape::stringKeyed($this->value($commentedOut, 'phpdoc_comments'));
        $typeSeverity = $this->stringMap($this->value($commentedOut, 'finding_severity'));
        $strictSeverity = $this->stringMap($this->value($commentedOut, 'finding_severity_strict'));
        ['enabled' => $ruleEnabled, 'severity' => $ruleSeverity] = $this->commentRules($comments);

        $this->applyControlsFromBundle($options, $bundle);
        $this->appendIgnorePaths($options, $ignorePaths);
        $this->applyCommentBooleanOptions($options, [
            'scanMarkers' => $scanMarkers,
            'commentedOutEnabled' => $commentedEnabled,
            'suppressionEnabled' => $suppressionEnabled,
            'allowOptionalReasonTagsInStrictMode' => $allowOptionalInStrict,
            'explain' => $explain,
            'docSignatureConsistency' => $docSignatureConsistency,
            'docTypeHygiene' => $docTypeHygiene,
        ]);
        $this->applyCommentListOptions($options, [
            'markerTags' => $markerTags,
            'allowedReasonTags' => $allowedReasonTags,
            'optionalReasonTags' => $optionalReasonTags,
            'allowedIssuePatterns' => $issuePatterns,
        ]);
        $this->applyCommentMapOptions($options, ['markerSeverity' => $markerSeverity]);
        $this->applyCommentStringOptions(
            $options,
            $suppressionDirective,
            $policy,
            $docMode,
            $failConfidence,
            $baseline,
            $writeBaseline,
            $docCacheEnabled,
            $docCacheFile,
        );
        $this->applyCommentNumericOptions($options, $minReasonLength, $maxBlockLines, $requireIssue);

        $singleAllowBlank = $this->boolValue($singleLine, 'allow_blank_line_between_reason_and_code');
        $blockAllowBefore = $this->boolValue($block, 'allow_reason_before_block_comment');
        $blockAllowBlank = $this->boolValue($block, 'allow_blank_line_between_reason_and_code');
        $phpdocAllowExamples = $this->boolValue($phpdoc, 'allow_documentation_examples');
        $phpdocLabels = $this->stringList($this->value($phpdoc, 'example_labels'));

        $this->applyCommentBooleanOptions($options, [
            'allowBlankLineBetweenReasonAndCode' => $singleAllowBlank,
            'allowReasonBeforeBlockComment' => $blockAllowBefore,
            'allowBlankLineBetweenReasonAndCodeInBlock' => $blockAllowBlank,
            'allowPhpdocExamples' => $phpdocAllowExamples,
        ]);
        $this->assignIfListNotEmpty($options, 'phpdocExampleLabels', $phpdocLabels);
        $this->applyCommentMapOptions($options, [
            'typeSeverity' => $typeSeverity,
            'strictSeverity' => $strictSeverity,
            'ruleSeverity' => $ruleSeverity,
        ]);
        $this->assignIfMapNotEmpty($options, 'ruleEnabled', $ruleEnabled);
        if ($customRules !== []) {
            $options['customRules'] = $customRules;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyDuplicateOptions(array $options): array
    {
        $bundle = $this->sectionBundle('duplicates', true);
        $values = $this->duplicateSectionValues($bundle['section']);
        [$mode, $normalize, $fuzzy, $nearMiss, $baseline, $writeBaseline] = $values['scalars'];
        [$minLines, $minTokens, $minStatements] = $values['thresholds'];
        [$minSimilarity, $cacheEnabled, $cacheFile, $ignoreFingerprints] = $values['optional'];

        $this->applyDuplicateScalarOptions(
            $options,
            $mode,
            $normalize,
            $fuzzy,
            $nearMiss,
            $baseline,
            $writeBaseline,
            $bundle['controls']['json'],
        );
        $this->applyDuplicateThresholdOptions($options, $minLines, $minTokens, $minStatements);
        $this->applyControlsFromBundle($options, $bundle);
        $this->applyDuplicateCacheAndSimilarity($options, $minSimilarity, $cacheEnabled, $cacheFile);
        $this->assignIfListNotEmpty($options, 'ignoreFingerprints', $ignoreFingerprints);

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applySyntaxOptions(array $options): array
    {
        $bundle = $this->sectionBundle('syntax', false);
        $section = $bundle['section'];
        $parallel = $this->intValue($section, 'parallel');
        $this->applyControlsFromBundle($options, $bundle);

        if ($parallel !== null) {
            $options['parallel'] = max(1, $parallel);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function asArray(): array
    {
        return $this->config;
    }

    public function merge(self $override): self
    {
        return new self($this->mergeArrays($this->config, $override->config));
    }

    public function preset(): ?string
    {
        return $this->stringValue($this->config, 'preset');
    }

    /**
     * @return list<string>
     */
    public function syntaxExcludes(): array
    {
        return $this->excludePaths($this->section('syntax'));
    }

    /**
     * @return list<string>
     */
    public function syntaxPaths(): array
    {
        return $this->stringList($this->value($this->section('syntax'), 'paths'));
    }

    /**
     * @param array<string, mixed> $section
     * @return array{?bool,?string,?string}
     */
    private function apiBaselineValues(array $section): array
    {
        return [
            $this->boolValue($section, 'include_protected'),
            $this->stringValue($section, 'baseline'),
            $this->stringValue($section, 'write_baseline'),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $ignorePaths
     */
    private function appendIgnorePaths(array &$options, array $ignorePaths): void
    {
        if ($ignorePaths !== []) {
            $options['excludes'] = array_values(array_unique([...$options['excludes'], ...$ignorePaths]));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyApiBaselineValues(
        array &$options,
        ?bool $includeProtected,
        ?string $baseline,
        ?string $writeBaseline,
    ): void {
        $this->assignIfNotNull($options, 'includeProtected', $includeProtected);
        $this->assignIfNotNull($options, 'baseline', $baseline);
        $this->assignIfNotNull($options, 'writeBaseline', $writeBaseline);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $values
     */
    private function applyCommentBooleanOptions(array &$options, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->assignIfNotNull($options, $key, $value);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, list<string>> $values
     */
    private function applyCommentListOptions(array &$options, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->assignIfListNotEmpty($options, $key, $value);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, array<string, string>> $values
     */
    private function applyCommentMapOptions(array &$options, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->assignIfMapNotEmpty($options, $key, $value);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyCommentNumericOptions(
        array &$options,
        ?int $minReasonLength,
        ?int $maxBlockLines,
        ?int $requireIssue,
    ): void {
        $this->assignPositiveMap($options, [
            'minReasonLength' => $minReasonLength,
            'maxAllowedBlockLines' => $maxBlockLines,
            'requireIssueForBlocksLongerThan' => $requireIssue,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyCommentStringOptions(
        array &$options,
        ?string $suppressionDirective,
        ?string $policy,
        ?string $docMode,
        ?string $failConfidence,
        ?string $baseline,
        ?string $writeBaseline,
        ?bool $docCacheEnabled,
        ?string $docCacheFile,
    ): void {
        foreach ([
            'suppressionDirective' => [$suppressionDirective, false],
            'policy' => [$policy, true],
            'docMode' => [$docMode, true],
            'failConfidence' => [$failConfidence, true],
            'baseline' => [$baseline, false],
            'writeBaseline' => [$writeBaseline, false],
        ] as $key => [$value, $lowercase]) {
            $this->assignNormalizedIfNotBlank($options, $key, $value, $lowercase);
        }

        $this->assignIfNotNull($options, 'docCacheEnabled', $docCacheEnabled);
        $this->assignNormalizedIfNotBlank($options, 'docCacheFile', $docCacheFile);
    }

    /**
     * @param array<string, mixed> $options
     * @param array{
     *     section:array<string, mixed>,
     *     controls:array{
     *         format:?string,
     *         json:?bool,
     *         failOn:?string,
     *         summaryJson:?string,
     *         changedOnly:?bool,
     *         changedBase:?string,
     *         paths:list<string>,
     *         excludes:list<string>
     *     }
     * } $bundle
     */
    private function applyControlsFromBundle(array &$options, array $bundle, ?string $failOn = null): void
    {
        $controls = $bundle['controls'];
        $this->applyOutputAndRunControls(
            $options,
            $controls['format'],
            $controls['json'],
            $failOn ?? $controls['failOn'],
            $controls['summaryJson'],
            $controls['changedOnly'],
            $controls['changedBase'],
        );
        $this->applyPathsAndExcludes($options, $controls['paths'], $controls['excludes']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyDuplicateCacheAndSimilarity(
        array &$options,
        ?float $minSimilarity,
        ?bool $cacheEnabled,
        ?string $cacheFile,
    ): void {
        if ($minSimilarity !== null) {
            $options['minSimilarity'] = $this->normalizeSimilarity($minSimilarity);
        }

        if ($cacheEnabled !== null) {
            $options['cacheEnabled'] = $cacheEnabled;
        }

        if ($cacheFile !== null && $cacheFile !== '') {
            $options['cacheFile'] = $cacheFile;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyDuplicateScalarOptions(
        array &$options,
        ?string $mode,
        ?bool $normalize,
        ?bool $fuzzy,
        ?bool $nearMiss,
        ?string $baseline,
        ?string $writeBaseline,
        ?bool $json,
    ): void {
        foreach ([
            'mode' => $mode,
            'json' => $json,
            'normalize' => $normalize,
            'fuzzy' => $fuzzy,
            'nearMiss' => $nearMiss,
            'baseline' => $baseline,
            'writeBaseline' => $writeBaseline,
        ] as $key => $value) {
            $this->assignIfNotNull($options, $key, $value);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyDuplicateThresholdOptions(
        array &$options,
        ?int $minLines,
        ?int $minTokens,
        ?int $minStatements,
    ): void {
        $this->assignPositiveMap($options, [
            'minLines' => $minLines,
            'minTokens' => $minTokens,
            'minStatements' => $minStatements,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyOutputAndRunControls(
        array &$options,
        ?string $format,
        ?bool $json,
        ?string $failOn,
        ?string $summaryJson,
        ?bool $changedOnly,
        ?string $changedBase,
    ): void {
        if ($format !== null && trim($format) !== '') {
            $options['format'] = strtolower(trim($format));
        } elseif ($json !== null) {
            $options['format'] = $json ? 'json' : 'text';
        }

        $this->assignNormalizedIfNotBlank($options, 'failOn', $failOn, true);
        $this->assignIfNotNull($options, 'summaryJson', $summaryJson);
        $this->assignIfNotNull($options, 'changedOnly', $changedOnly);
        $this->assignIfNotNull($options, 'changedBase', $changedBase);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $paths
     * @param list<string> $excludes
     */
    private function applyPathsAndExcludes(array &$options, array $paths, array $excludes): void
    {
        $this->assignIfListNotEmpty($options, 'paths', $paths);
        $this->assignIfListNotEmpty($options, 'excludes', $excludes);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $value
     */
    private function assignIfListNotEmpty(array &$options, string $key, array $value): void
    {
        if ($value !== []) {
            $options[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assignIfMapNotEmpty(array &$options, string $key, array $value): void
    {
        if ($value !== []) {
            $options[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assignIfNotNull(array &$options, string $key, mixed $value): void
    {
        if ($value !== null) {
            $options[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assignNormalizedIfNotBlank(array &$options, string $key, ?string $value, bool $lowercase = false): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }

        $normalized = trim($value);
        $options[$key] = $lowercase ? strtolower($normalized) : $normalized;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assignPositiveIfNotNull(array &$options, string $key, ?int $value): void
    {
        if ($value !== null) {
            $options[$key] = max(1, $value);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, ?int> $values
     */
    private function assignPositiveMap(array &$options, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->assignPositiveIfNotNull($options, $key, $value);
        }
    }

    /**
     * @param array<string, mixed> $section
     */
    private function boolValue(array $section, string $key): ?bool
    {
        $value = $this->value($section, $key);

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $comments
     * @return array{enabled:array<string,bool>,severity:array<string,string>}
     */
    private function commentRules(array $comments): array
    {
        $rules = ArrayShape::stringKeyed($this->value($comments, 'rules'));
        $enabled = [];
        $severity = [];

        foreach ($rules as $rule => $config) {
            if (!is_string($rule)) {
                continue;
            }

            $entry = ArrayShape::stringKeyed($config);
            $ruleEnabled = $this->boolValue($entry, 'enabled');
            $ruleSeverity = $this->stringValue($entry, 'severity');

            if ($ruleEnabled !== null) {
                $enabled[$rule] = $ruleEnabled;
            }

            if (is_string($ruleSeverity) && trim($ruleSeverity) !== '') {
                $severity[$rule] = strtolower(trim($ruleSeverity));
            }
        }

        return ['enabled' => $enabled, 'severity' => $severity];
    }

    /**
     * @param array<string, mixed> $comments
     * @return list<array{
     *     id:string,
     *     pattern:string,
     *     severity:string,
     *     message:string,
     *     enabled:bool,
     *     scope:string
     * }>
     */
    private function customCommentRules(array $comments): array
    {
        $items = $this->value($comments, 'custom_rules');

        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }

        $rules = [];

        foreach ($items as $item) {
            $entry = ArrayShape::stringKeyed($item);
            $id = $this->stringValue($entry, 'id');
            $pattern = $this->stringValue($entry, 'pattern');
            $severity = strtolower(trim($this->stringValue($entry, 'severity') ?? 'warning'));
            $message = $this->stringValue($entry, 'message') ?? '';
            $enabled = $this->boolValue($entry, 'enabled');
            $scope = strtolower(trim($this->stringValue($entry, 'scope') ?? 'all'));

            if (!is_string($id) || trim($id) === '' || !is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            $rules[] = [
                'id' => trim($id),
                'pattern' => trim($pattern),
                'severity' => $severity,
                'message' => trim($message) !== '' ? trim($message) : sprintf('Matched custom comment rule "%s".', trim($id)),
                'enabled' => $enabled ?? true,
                'scope' => in_array($scope, ['all', 'line', 'block', 'doc'], true) ? $scope : 'all',
            ];
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $section
     * @return array{scalars:array{?string,?bool,?bool,?bool,?string,?string},thresholds:array{?int,?int,?int},optional:array{?float,?bool,?string,list<string>}}
     */
    private function duplicateSectionValues(array $section): array
    {
        $cache = ArrayShape::stringKeyed($this->value($section, 'cache'));

        return [
            'scalars' => [
                $this->stringValue($section, 'mode'),
                $this->boolValue($section, 'normalize'),
                $this->boolValue($section, 'fuzzy'),
                $this->boolValue($section, 'near_miss'),
                $this->stringValue($section, 'baseline'),
                $this->stringValue($section, 'write_baseline'),
            ],
            'thresholds' => [
                $this->intValue($section, 'min_lines'),
                $this->intValue($section, 'min_tokens'),
                $this->intValue($section, 'min_statements'),
            ],
            'optional' => [
                $this->floatValue($section, 'min_similarity'),
                $this->boolValue($cache, 'enabled'),
                $this->stringValue($cache, 'file'),
                $this->stringList($this->value($section, 'ignore_fingerprints')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return list<string>
     */
    private function excludePaths(array $section): array
    {
        return array_values(array_unique([
            ...$this->stringList($this->value($section, 'exclude')),
            ...$this->stringList($this->value($section, 'exclude_paths')),
        ]));
    }

    /**
     * @param array<string, mixed> $section
     */
    private function floatValue(array $section, string $key): ?float
    {
        $value = $this->value($section, $key);

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function intValue(array $section, string $key): ?int
    {
        $value = $this->value($section, $key);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeArrays(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $baseValue = $base[$key] ?? null;

            $base[$key] = is_array($baseValue) && is_array($value) && !array_is_list($baseValue) && !array_is_list($value)
                ? $this->mergeArrays(ArrayShape::stringKeyed($baseValue), ArrayShape::stringKeyed($value))
                : $value;
        }

        return $base;
    }

    private function normalizeSimilarity(float $value): float
    {
        return $value > 1.0 ? min(100.0, $value) / 100.0 : max(0.0, min(1.0, $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $name): array
    {
        $section = $this->config[$name] ?? [];

        return ArrayShape::stringKeyed($section);
    }

    /**
     * @return array{
     *     section:array<string, mixed>,
     *     controls:array{
     *         format:?string,
     *         json:?bool,
     *         failOn:?string,
     *         summaryJson:?string,
     *         changedOnly:?bool,
     *         changedBase:?string,
     *         paths:list<string>,
     *         excludes:list<string>
     *     }
     * }
     */
    private function sectionBundle(string $name, bool $withFailOn): array
    {
        $section = $this->section($name);

        return [
            'section' => $section,
            'controls' => $this->sectionControls($section, $withFailOn),
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return array{
     *     format:?string,
     *     json:?bool,
     *     failOn:?string,
     *     summaryJson:?string,
     *     changedOnly:?bool,
     *     changedBase:?string,
     *     paths:list<string>,
     *     excludes:list<string>
     * }
     */
    private function sectionControls(array $section, bool $withFailOn): array
    {
        return [
            'format' => $this->stringValue($section, 'format'),
            'json' => $this->boolValue($section, 'json'),
            'failOn' => $withFailOn ? $this->stringValue($section, 'fail_on') : null,
            'summaryJson' => $this->stringValue($section, 'summary_json'),
            'changedOnly' => $this->boolValue($section, 'changed_only'),
            'changedBase' => $this->stringValue($section, 'changed_base'),
            'paths' => $this->stringList($this->value($section, 'paths')),
            'excludes' => $this->excludePaths($section),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, string>
     */
    private function stringMap(mixed $value, bool $uppercaseKeys = false): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item) && $item !== '') {
                $map[$uppercaseKeys ? strtoupper($key) : $key] = $item;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function stringValue(array $section, string $key): ?string
    {
        $value = $this->value($section, $key);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function value(array $section, string $key): mixed
    {
        foreach ([$key, str_replace('_', '-', $key), lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))))] as $name) {
            if (array_key_exists($name, $section)) {
                return $section[$name];
            }
        }

        return null;
    }
}

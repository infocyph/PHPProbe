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
     * @return array<string, mixed>
     */
    public function asArray(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyDuplicateOptions(array $options): array
    {
        $section = $this->section('duplicates');

        $mode = $this->stringValue($section, 'mode');
        $json = $this->boolValue($section, 'json');
        $format = $this->stringValue($section, 'format');
        $normalize = $this->boolValue($section, 'normalize');
        $fuzzy = $this->boolValue($section, 'fuzzy');
        $nearMiss = $this->boolValue($section, 'near_miss');
        $minLines = $this->intValue($section, 'min_lines');
        $minTokens = $this->intValue($section, 'min_tokens');
        $minStatements = $this->intValue($section, 'min_statements');
        $minSimilarity = $this->floatValue($section, 'min_similarity');
        $baseline = $this->stringValue($section, 'baseline');
        $writeBaseline = $this->stringValue($section, 'write_baseline');
        $failOn = $this->stringValue($section, 'fail_on');
        $summaryJson = $this->stringValue($section, 'summary_json');
        $changedOnly = $this->boolValue($section, 'changed_only');
        $changedBase = $this->stringValue($section, 'changed_base');
        $cacheEnabled = $this->boolValue(ArrayShape::stringKeyed($this->value($section, 'cache')), 'enabled');
        $cacheFile = $this->stringValue(ArrayShape::stringKeyed($this->value($section, 'cache')), 'file');

        $paths = $this->stringList($this->value($section, 'paths'));
        $excludes = $this->excludePaths($section);

        $this->applyDuplicateScalarOptions(
            $options,
            $mode,
            $json,
            $normalize,
            $fuzzy,
            $nearMiss,
            $baseline,
            $writeBaseline,
        );
        $this->applyDuplicateThresholdOptions($options, $minLines, $minTokens, $minStatements);

        if ($format !== null && $format !== '') {
            $options['format'] = strtolower(trim($format));
        } elseif ($json !== null) {
            $options['format'] = $json ? 'json' : 'text';
        }

        if ($minSimilarity !== null) {
            $options['minSimilarity'] = $this->normalizeSimilarity($minSimilarity);
        }

        if ($failOn !== null && $failOn !== '') {
            $options['failOn'] = strtolower(trim($failOn));
        }

        if ($summaryJson !== null) {
            $options['summaryJson'] = $summaryJson;
        }

        if ($changedOnly !== null) {
            $options['changedOnly'] = $changedOnly;
        }

        if ($changedBase !== null) {
            $options['changedBase'] = $changedBase;
        }

        if ($cacheEnabled !== null) {
            $options['cacheEnabled'] = $cacheEnabled;
        }

        if ($cacheFile !== null && $cacheFile !== '') {
            $options['cacheFile'] = $cacheFile;
        }

        if ($paths !== []) {
            $options['paths'] = $paths;
        }

        if ($excludes !== []) {
            $options['excludes'] = $excludes;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyApiOptions(array $options): array
    {
        $section = $this->section('api');

        $json = $this->boolValue($section, 'json');
        $format = $this->stringValue($section, 'format');
        $includeProtected = $this->boolValue($section, 'include_protected');
        $baseline = $this->stringValue($section, 'baseline');
        $writeBaseline = $this->stringValue($section, 'write_baseline');
        $failOn = $this->stringValue($section, 'fail_on');
        $summaryJson = $this->stringValue($section, 'summary_json');
        $changedOnly = $this->boolValue($section, 'changed_only');
        $changedBase = $this->stringValue($section, 'changed_base');
        $paths = $this->stringList($this->value($section, 'paths'));
        $excludes = $this->excludePaths($section);

        if ($format !== null && $format !== '') {
            $options['format'] = strtolower(trim($format));
        } elseif ($json !== null) {
            $options['format'] = $json ? 'json' : 'text';
        }

        if ($includeProtected !== null) {
            $options['includeProtected'] = $includeProtected;
        }

        if ($baseline !== null) {
            $options['baseline'] = $baseline;
        }

        if ($writeBaseline !== null) {
            $options['writeBaseline'] = $writeBaseline;
        }

        if ($failOn !== null && $failOn !== '') {
            $options['failOn'] = strtolower(trim($failOn));
        }

        if ($summaryJson !== null) {
            $options['summaryJson'] = $summaryJson;
        }

        if ($changedOnly !== null) {
            $options['changedOnly'] = $changedOnly;
        }

        if ($changedBase !== null) {
            $options['changedBase'] = $changedBase;
        }

        if ($paths !== []) {
            $options['paths'] = $paths;
        }

        if ($excludes !== []) {
            $options['excludes'] = $excludes;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applySyntaxOptions(array $options): array
    {
        $section = $this->section('syntax');
        $format = $this->stringValue($section, 'format');
        $json = $this->boolValue($section, 'json');
        $summaryJson = $this->stringValue($section, 'summary_json');
        $changedOnly = $this->boolValue($section, 'changed_only');
        $changedBase = $this->stringValue($section, 'changed_base');
        $parallel = $this->intValue($section, 'parallel');
        $paths = $this->stringList($this->value($section, 'paths'));
        $excludes = $this->excludePaths($section);

        if ($format !== null && $format !== '') {
            $options['format'] = strtolower(trim($format));
        } elseif ($json !== null) {
            $options['format'] = $json ? 'json' : 'text';
        }

        if ($summaryJson !== null) {
            $options['summaryJson'] = $summaryJson;
        }

        if ($changedOnly !== null) {
            $options['changedOnly'] = $changedOnly;
        }

        if ($changedBase !== null) {
            $options['changedBase'] = $changedBase;
        }

        if ($parallel !== null) {
            $options['parallel'] = max(1, $parallel);
        }

        if ($paths !== []) {
            $options['paths'] = $paths;
        }

        if ($excludes !== []) {
            $options['excludes'] = $excludes;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyCommentOptions(array $options): array
    {
        $comments = $this->section('comments');
        $commentedOut = $this->section('commented_out_code');
        $paths = $this->stringList($this->value($comments, 'paths'));
        $excludes = $this->excludePaths($comments);
        $format = $this->stringValue($comments, 'format');
        $json = $this->boolValue($comments, 'json');
        $failOn = $this->stringValue($comments, 'fail_on');
        $summaryJson = $this->stringValue($comments, 'summary_json');
        $changedOnly = $this->boolValue($comments, 'changed_only');
        $changedBase = $this->stringValue($comments, 'changed_base');
        $scanMarkers = $this->boolValue($comments, 'scan_markers');
        $markerTags = array_map('strtoupper', $this->stringList($this->value($comments, 'marker_tags')));
        $markerSeverity = $this->stringMap($this->value($comments, 'marker_severity'), true);
        $commentedEnabled = $this->boolValue($commentedOut, 'enabled');
        $allowedReasonTags = array_map('strtoupper', $this->stringList($this->value($commentedOut, 'allowed_reason_tags')));
        $optionalReasonTags = array_map('strtoupper', $this->stringList($this->value($commentedOut, 'optional_reason_tags')));
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

        if ($format !== null && $format !== '') {
            $options['format'] = strtolower(trim($format));
        } elseif ($json !== null) {
            $options['format'] = $json ? 'json' : 'text';
        }

        if ($failOn !== null && $failOn !== '') {
            $options['failOn'] = strtolower(trim($failOn));
        }

        if ($summaryJson !== null) {
            $options['summaryJson'] = $summaryJson;
        }

        if ($changedOnly !== null) {
            $options['changedOnly'] = $changedOnly;
        }

        if ($changedBase !== null) {
            $options['changedBase'] = $changedBase;
        }

        if ($paths !== []) {
            $options['paths'] = $paths;
        }

        if ($excludes !== []) {
            $options['excludes'] = $excludes;
        }

        if ($ignorePaths !== []) {
            $options['excludes'] = array_values(array_unique([...$options['excludes'], ...$ignorePaths]));
        }

        if ($scanMarkers !== null) {
            $options['scanMarkers'] = $scanMarkers;
        }

        if ($markerTags !== []) {
            $options['markerTags'] = $markerTags;
        }

        if ($markerSeverity !== []) {
            $options['markerSeverity'] = $markerSeverity;
        }

        if ($commentedEnabled !== null) {
            $options['commentedOutEnabled'] = $commentedEnabled;
        }

        if ($allowedReasonTags !== []) {
            $options['allowedReasonTags'] = $allowedReasonTags;
        }

        if ($optionalReasonTags !== []) {
            $options['optionalReasonTags'] = $optionalReasonTags;
        }

        if ($suppressionEnabled !== null) {
            $options['suppressionEnabled'] = $suppressionEnabled;
        }

        if ($suppressionDirective !== null && trim($suppressionDirective) !== '') {
            $options['suppressionDirective'] = trim($suppressionDirective);
        }

        if ($policy !== null && trim($policy) !== '') {
            $options['policy'] = strtolower(trim($policy));
        }

        if ($allowOptionalInStrict !== null) {
            $options['allowOptionalReasonTagsInStrictMode'] = $allowOptionalInStrict;
        }

        if ($minReasonLength !== null) {
            $options['minReasonLength'] = max(1, $minReasonLength);
        }

        if ($maxBlockLines !== null) {
            $options['maxAllowedBlockLines'] = max(1, $maxBlockLines);
        }

        if ($requireIssue !== null) {
            $options['requireIssueForBlocksLongerThan'] = max(1, $requireIssue);
        }

        if ($issuePatterns !== []) {
            $options['allowedIssuePatterns'] = $issuePatterns;
        }

        $singleAllowBlank = $this->boolValue($singleLine, 'allow_blank_line_between_reason_and_code');
        $blockAllowBefore = $this->boolValue($block, 'allow_reason_before_block_comment');
        $blockAllowBlank = $this->boolValue($block, 'allow_blank_line_between_reason_and_code');
        $phpdocAllowExamples = $this->boolValue($phpdoc, 'allow_documentation_examples');
        $phpdocLabels = $this->stringList($this->value($phpdoc, 'example_labels'));

        if ($singleAllowBlank !== null) {
            $options['allowBlankLineBetweenReasonAndCode'] = $singleAllowBlank;
        }

        if ($blockAllowBefore !== null) {
            $options['allowReasonBeforeBlockComment'] = $blockAllowBefore;
        }

        if ($blockAllowBlank !== null) {
            $options['allowBlankLineBetweenReasonAndCodeInBlock'] = $blockAllowBlank;
        }

        if ($phpdocAllowExamples !== null) {
            $options['allowPhpdocExamples'] = $phpdocAllowExamples;
        }

        if ($phpdocLabels !== []) {
            $options['phpdocExampleLabels'] = $phpdocLabels;
        }

        if ($typeSeverity !== []) {
            $options['typeSeverity'] = $typeSeverity;
        }

        if ($strictSeverity !== []) {
            $options['strictSeverity'] = $strictSeverity;
        }

        return $options;
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
     * @param array<string, mixed> $options
     */
    private function applyDuplicateScalarOptions(
        array &$options,
        ?string $mode,
        ?bool $json,
        ?bool $normalize,
        ?bool $fuzzy,
        ?bool $nearMiss,
        ?string $baseline,
        ?string $writeBaseline,
    ): void {
        if ($mode !== null) {
            $options['mode'] = $mode;
        }

        if ($json !== null) {
            $options['json'] = $json;
        }

        if ($normalize !== null) {
            $options['normalize'] = $normalize;
        }

        if ($fuzzy !== null) {
            $options['fuzzy'] = $fuzzy;
        }

        if ($nearMiss !== null) {
            $options['nearMiss'] = $nearMiss;
        }

        if ($baseline !== null) {
            $options['baseline'] = $baseline;
        }

        if ($writeBaseline !== null) {
            $options['writeBaseline'] = $writeBaseline;
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
        if ($minLines !== null) {
            $options['minLines'] = max(1, $minLines);
        }

        if ($minTokens !== null) {
            $options['minTokens'] = max(1, $minTokens);
        }

        if ($minStatements !== null) {
            $options['minStatements'] = max(1, $minStatements);
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
     * @return array<string, mixed>
     */
    private function section(string $name): array
    {
        $section = $this->config[$name] ?? [];

        return ArrayShape::stringKeyed($section);
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

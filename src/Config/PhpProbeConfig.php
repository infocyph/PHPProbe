<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final readonly class PhpProbeConfig
{
    /**
     * @param array<string, mixed> $config
     */
    private function __construct(private array $config) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $context = 'configuration'): self
    {
        $errors = new ConfigValidator()->validate($config);

        if ($errors !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid PHPProbe %s:%s- %s',
                $context,
                PHP_EOL,
                implode(PHP_EOL . '- ', $errors),
            ));
        }

        return new self($config);
    }

    public static function fromFile(string $path): self
    {
        $validator = new ConfigValidator();
        $config = $validator->decodeFile($path);
        $errors = $validator->validate($config);

        if ($errors !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid PHPProbe config at %s:%s- %s',
                $path,
                PHP_EOL,
                implode(PHP_EOL . '- ', $errors),
            ));
        }

        return new self($config);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyCommentOptions(array $options): array
    {
        $comments = $this->section('comments');
        $options = $this->applyCommon($options, $comments);
        $options = $this->applyMappedOptions($options, $comments, $this->reportOptionMap());

        $options = $this->applyMappedOptions($options, $comments, [
            'fail_confidence' => 'failConfidence',
            'doc_mode' => 'docMode',
            'doc_signature_consistency' => 'docSignatureConsistency',
            'doc_type_hygiene' => 'docTypeHygiene',
            'explain' => 'explain',
            'scan_markers' => 'scanMarkers',
            'marker_tags' => 'markerTags',
            'marker_severity' => 'markerSeverity',
            'custom_rules' => 'customRules',
        ]);
        $this->applyCacheOptions($options, $comments['doc_cache'] ?? null, 'docCacheEnabled', 'docCacheFile');

        $this->applyCommentRules($options, $this->object($comments['rules'] ?? null));
        $this->applyCommentedOutCode($options, $this->section('commented_out_code'));

        return $this->applyColors($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyDuplicateOptions(array $options): array
    {
        $section = $this->section('duplicates');
        $options = $this->applyCommon($options, $section);
        $options = $this->applyMappedOptions($options, $section, $this->reportOptionMap());
        $options = $this->applyMappedOptions($options, $section, [
            'mode' => 'mode',
            'normalize' => 'normalize',
            'fuzzy' => 'fuzzy',
            'near_miss' => 'nearMiss',
            'min_lines' => 'minLines',
            'min_tokens' => 'minTokens',
            'min_statements' => 'minStatements',
            'min_similarity' => 'minSimilarity',
            'max_near_miss_comparisons' => 'maxNearMissComparisons',
            'ignore_fingerprints' => 'ignoreFingerprints',
            'error_duplicate_percentage' => 'errorDuplicatePercentage',
        ]);
        $this->applyCacheOptions($options, $section['cache'] ?? null, 'cacheEnabled', 'cacheFile');

        $output = $this->object($section['output'] ?? null);

        if (is_string($output['style'] ?? null)) {
            $options['outputStyle'] = $output['style'];
        }

        $bands = $this->object($output['score_colors'] ?? null);

        foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'base' => 'Base'] as $band => $suffix) {
            $entry = $this->object($bands[$band] ?? null);

            if (is_int($entry['min'] ?? null) || is_float($entry['min'] ?? null)) {
                $options['scoreColor' . $suffix . 'Min'] = (float) $entry['min'];
            }

            if (is_string($entry['color'] ?? null)) {
                $options['scoreColor' . $suffix] = $entry['color'];
            }
        }

        return $this->applyColors($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applySyntaxOptions(array $options): array
    {
        $section = $this->section('syntax');
        $options = $this->applyCommon($options, $section);

        if (isset($section['parallel'])) {
            $options['parallel'] = $section['parallel'];
        }

        if (is_int($section['timeout'] ?? null) || is_float($section['timeout'] ?? null)) {
            $options['timeout'] = (float) $section['timeout'];
        }

        return $this->applyColors($options);
    }

    public function merge(self $overrides): self
    {
        return new self($this->mergeArrays($this->config, $overrides->config));
    }

    public function preset(): ?string
    {
        $preset = $this->config['preset'] ?? null;

        return is_string($preset) && $preset !== '' ? $preset : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyCacheOptions(
        array &$options,
        mixed $value,
        string $enabledOption,
        string $fileOption,
    ): void {
        $cache = $this->object($value);

        if (array_key_exists('enabled', $cache)) {
            $options[$enabledOption] = $cache['enabled'];
        }

        if (is_string($cache['file'] ?? null) && $cache['file'] !== '') {
            $options[$fileOption] = $cache['file'];
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function applyColors(array $options): array
    {
        $colors = $this->object($this->section('output')['colors'] ?? null);
        $map = [
            'success' => 'textColorSuccess',
            'error' => 'textColorError',
            'warning' => 'textColorWarning',
            'info' => 'textColorInfo',
            'file' => 'textColorFile',
        ];

        foreach ($map as $key => $option) {
            if (is_string($colors[$key] ?? null)) {
                $options[$option] = $colors[$key];
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $section
     */
    private function applyCommentedOutCode(array &$options, array $section): void
    {
        foreach ([
            'enabled' => 'commentedOutEnabled',
            'policy' => 'policy',
            'allowed_reason_tags' => 'allowedReasonTags',
            'optional_reason_tags' => 'optionalReasonTags',
            'allow_optional_reason_tags_in_strict_mode' => 'allowOptionalReasonTagsInStrictMode',
            'min_reason_length' => 'minReasonLength',
            'max_allowed_block_lines' => 'maxAllowedBlockLines',
            'require_issue_for_blocks_longer_than' => 'requireIssueForBlocksLongerThan',
            'allowed_issue_patterns' => 'allowedIssuePatterns',
            'finding_severity' => 'typeSeverity',
            'finding_severity_strict' => 'strictSeverity',
        ] as $key => $option) {
            if (array_key_exists($key, $section)) {
                $options[$option] = $section[$key];
            }
        }

        $ignorePaths = $section['ignore_paths'] ?? [];

        if (is_array($ignorePaths) && array_is_list($ignorePaths)) {
            $configuredExcludes = is_array($options['excludes'] ?? null) && array_is_list($options['excludes'])
                ? array_values(array_filter($options['excludes'], is_string(...)))
                : [];
            $commentExcludes = array_values(array_filter($ignorePaths, is_string(...)));
            $options['excludes'] = array_values(array_unique([...$configuredExcludes, ...$commentExcludes]));
        }

        foreach ([
            'suppression' => [
                'enabled' => 'suppressionEnabled',
                'directive' => 'suppressionDirective',
            ],
            'single_line_comments' => [
                'allow_blank_line_between_reason_and_code' => 'allowBlankLineBetweenReasonAndCode',
            ],
            'block_comments' => [
                'allow_reason_before_block_comment' => 'allowReasonBeforeBlockComment',
                'allow_blank_line_between_reason_and_code' => 'allowBlankLineBetweenReasonAndCodeInBlock',
            ],
            'phpdoc_comments' => [
                'allow_documentation_examples' => 'allowPhpdocExamples',
                'apply_limits_to_phpdoc' => 'applyLimitsToPhpdoc',
                'example_labels' => 'phpdocExampleLabels',
            ],
        ] as $sectionKey => $map) {
            $nested = $this->object($section[$sectionKey] ?? null);

            foreach ($map as $key => $option) {
                if (array_key_exists($key, $nested)) {
                    $options[$option] = $nested[$key];
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $rules
     */
    private function applyCommentRules(array &$options, array $rules): void
    {
        $enabled = [];
        $severity = [];

        foreach ($rules as $name => $value) {
            $rule = $this->object($value);

            if (is_bool($rule['enabled'] ?? null)) {
                $enabled[$name] = $rule['enabled'];
            }

            if (is_string($rule['severity'] ?? null)) {
                $severity[$name] = $rule['severity'];
            }
        }

        if ($enabled !== []) {
            $options['ruleEnabled'] = $enabled;
        }

        if ($severity !== []) {
            $options['ruleSeverity'] = $severity;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function applyCommon(array $options, array $section): array
    {
        $map = [
            'paths' => 'paths',
            'exclude' => 'excludes',
            'format' => 'format',
            'summary_json' => 'summaryJson',
            'changed_only' => 'changedOnly',
            'changed_base' => 'changedBase',
        ];

        foreach ($map as $key => $option) {
            if (array_key_exists($key, $section)) {
                $options[$option] = $section[$key];
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $section
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    private function applyMappedOptions(array $options, array $section, array $map): array
    {
        foreach ($map as $key => $option) {
            if (array_key_exists($key, $section)) {
                $options[$option] = $section[$key];
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mergeArrays(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($base[$key])
                && is_array($value)
                && !array_is_list($value)
            ) {
                /** @var array<string, mixed> $baseValue */
                $baseValue = $base[$key];
                /** @var array<string, mixed> $overrideValue */
                $overrideValue = $value;
                $base[$key] = $this->mergeArrays($baseValue, $overrideValue);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return [];
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /** @return array<string, string> */
    private function reportOptionMap(): array
    {
        return [
            'baseline' => 'baseline',
            'write_baseline' => 'writeBaseline',
            'fail_on' => 'failOn',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $name): array
    {
        return $this->object($this->config[$name] ?? null);
    }
}

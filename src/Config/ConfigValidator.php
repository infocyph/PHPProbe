<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final class ConfigValidator
{
    /** @var list<string> */
    private const array COLORS = ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'];

    /** @var list<string> */
    private const array FORMATS = ['text', 'json', 'markdown', 'sarif', 'github'];

    private const int MAX_CONFIG_BYTES = 1_048_576;

    /**
     * @return array<string, mixed>
     */
    public function decodeFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Config file not found: %s', $path));
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('Config file is not readable: %s', $path));
        }

        $size = filesize($path);

        if (is_int($size) && $size > self::MAX_CONFIG_BYTES) {
            throw new \RuntimeException(sprintf('Config file exceeds the %d-byte limit: %s', self::MAX_CONFIG_BYTES, $path));
        }

        $contents = file_get_contents($path);

        if (!is_string($contents) || trim($contents) === '') {
            throw new \RuntimeException(sprintf('Config file is empty: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Invalid config JSON at %s: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException(sprintf('Config root must be a JSON object: %s', $path));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public function validate(array $config): array
    {
        $errors = [];
        $this->unknownKeys('root', $config, ['preset', 'output', 'syntax', 'duplicates', 'comments', 'commented_out_code'], $errors);

        if (array_key_exists('preset', $config)) {
            $this->enum('root.preset', $config['preset'], PresetRepository::NAMES, $errors);
        }

        $this->output($config['output'] ?? null, $errors);
        $this->checker('syntax', $config['syntax'] ?? null, ['parallel', 'timeout'], $errors);
        $this->checker('duplicates', $config['duplicates'] ?? null, [
            'mode',
            'normalize',
            'fuzzy',
            'near_miss',
            'min_lines',
            'min_tokens',
            'min_statements',
            'min_similarity',
            'max_near_miss_comparisons',
            'baseline',
            'write_baseline',
            'ignore_fingerprints',
            'fail_on',
            'error_duplicate_percentage',
            'cache',
            'output',
        ], $errors);
        $this->checker('comments', $config['comments'] ?? null, [
            'fail_on',
            'fail_confidence',
            'doc_mode',
            'doc_signature_consistency',
            'doc_type_hygiene',
            'explain',
            'baseline',
            'write_baseline',
            'scan_markers',
            'marker_tags',
            'marker_severity',
            'custom_rules',
            'doc_cache',
            'rules',
        ], $errors);

        $this->syntaxValues($config['syntax'] ?? null, $errors);
        $this->duplicateValues($config['duplicates'] ?? null, $errors);
        $this->commentValues($config['comments'] ?? null, $errors);
        $this->commentedOutValues($config['commented_out_code'] ?? null, $errors);

        return $errors;
    }

    /**
     * @return list<string>
     */
    public function validateFile(string $path): array
    {
        return $this->validate($this->decodeFile($path));
    }

    /**
     * @param list<string> $errors
     */
    private function cacheValues(string $path, mixed $value, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $path . ' must be a JSON object.';

            return;
        }

        $this->unknownKeys($path, $value, ['enabled', 'file'], $errors);
        $this->optionalBool($path . '.enabled', $value['enabled'] ?? null, $errors);
        $this->optionalString($path . '.file', $value['file'] ?? null, $errors);
    }

    /**
     * @param list<string> $extraKeys
     * @param list<string> $errors
     */
    private function checker(string $name, mixed $value, array $extraKeys, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[] = sprintf('%s must be a JSON object.', $name);

            return;
        }

        $common = ['paths', 'exclude', 'format', 'summary_json', 'changed_only', 'changed_base'];
        $this->unknownKeys($name, $value, [...$common, ...$extraKeys], $errors);
        $this->stringList($name . '.paths', $value['paths'] ?? null, $errors);
        $this->stringList($name . '.exclude', $value['exclude'] ?? null, $errors);
        $this->optionalString($name . '.summary_json', $value['summary_json'] ?? null, $errors);
        $this->optionalString($name . '.changed_base', $value['changed_base'] ?? null, $errors);
        $this->optionalBool($name . '.changed_only', $value['changed_only'] ?? null, $errors);

        if (array_key_exists('format', $value)) {
            $this->enum($name . '.format', $value['format'], self::FORMATS, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function commentCustomRules(mixed $value, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = 'comments.custom_rules must be a list.';

            return;
        }

        foreach ($value as $index => $rule) {
            $path = sprintf('comments.custom_rules[%d]', $index);

            if (!is_array($rule) || array_is_list($rule)) {
                $errors[] = $path . ' must be a JSON object.';

                continue;
            }

            $this->unknownKeys($path, $rule, ['id', 'pattern', 'severity', 'scope', 'message', 'enabled'], $errors);

            foreach (['id', 'pattern'] as $key) {
                if (!is_string($rule[$key] ?? null) || trim($rule[$key]) === '') {
                    $errors[] = sprintf('%s.%s must be a non-empty string.', $path, $key);
                }
            }

            if (array_key_exists('severity', $rule)) {
                $this->severity($path . '.severity', $rule['severity'], $errors);
            }

            if (array_key_exists('scope', $rule)) {
                $this->enum($path . '.scope', $rule['scope'], ['all', 'line', 'block', 'doc'], $errors);
            }

            $this->optionalString($path . '.message', $rule['message'] ?? null, $errors);
            $this->optionalBool($path . '.enabled', $rule['enabled'] ?? null, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function commentDocCache(mixed $value, array &$errors): void
    {
        $this->cacheValues('comments.doc_cache', $value, $errors);
    }

    /**
     * @param list<string> $errors
     */
    private function commentedOutValues(mixed $value, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[] = 'commented_out_code must be a JSON object.';

            return;
        }

        $this->unknownKeys('commented_out_code', $value, [
            'enabled',
            'policy',
            'allowed_reason_tags',
            'optional_reason_tags',
            'allow_optional_reason_tags_in_strict_mode',
            'ignore_paths',
            'suppression',
            'min_reason_length',
            'max_allowed_block_lines',
            'require_issue_for_blocks_longer_than',
            'allowed_issue_patterns',
            'single_line_comments',
            'block_comments',
            'phpdoc_comments',
            'finding_severity',
            'finding_severity_strict',
        ], $errors);

        $this->optionalBool('commented_out_code.enabled', $value['enabled'] ?? null, $errors);
        $this->optionalBool('commented_out_code.allow_optional_reason_tags_in_strict_mode', $value['allow_optional_reason_tags_in_strict_mode'] ?? null, $errors);

        if (array_key_exists('policy', $value)) {
            $this->enum('commented_out_code.policy', $value['policy'], ['relaxed', 'standard', 'strict'], $errors);
        }

        foreach (['allowed_reason_tags', 'optional_reason_tags', 'ignore_paths', 'allowed_issue_patterns'] as $key) {
            $this->stringList('commented_out_code.' . $key, $value[$key] ?? null, $errors);
        }

        foreach (['min_reason_length', 'max_allowed_block_lines'] as $key) {
            if (array_key_exists($key, $value)) {
                $this->integer('commented_out_code.' . $key, $value[$key], 1, $errors);
            }
        }

        if (array_key_exists('require_issue_for_blocks_longer_than', $value)) {
            $this->integer('commented_out_code.require_issue_for_blocks_longer_than', $value['require_issue_for_blocks_longer_than'], 0, $errors);
        }

        $this->nestedObject('commented_out_code.suppression', $value['suppression'] ?? null, [
            'enabled' => 'bool',
            'directive' => 'string',
        ], $errors);
        $this->nestedObject('commented_out_code.single_line_comments', $value['single_line_comments'] ?? null, [
            'allow_blank_line_between_reason_and_code' => 'bool',
        ], $errors);
        $this->nestedObject('commented_out_code.block_comments', $value['block_comments'] ?? null, [
            'allow_reason_before_block_comment' => 'bool',
            'allow_blank_line_between_reason_and_code' => 'bool',
        ], $errors);
        $this->nestedObject('commented_out_code.phpdoc_comments', $value['phpdoc_comments'] ?? null, [
            'allow_documentation_examples' => 'bool',
            'apply_limits_to_phpdoc' => 'bool',
            'example_labels' => 'strings',
        ], $errors);
        $this->severityMap('commented_out_code.finding_severity', $value['finding_severity'] ?? null, $errors);
        $this->severityMap('commented_out_code.finding_severity_strict', $value['finding_severity_strict'] ?? null, $errors);
    }

    /**
     * @param list<string> $errors
     */
    private function commentRules(mixed $value, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = 'comments.rules must be a JSON object.';

            return;
        }

        foreach ($value as $name => $rule) {
            $path = 'comments.rules.' . $name;

            if (!is_string($name) || trim($name) === '' || !is_array($rule) || array_is_list($rule)) {
                $errors[] = $path . ' must be a named JSON object.';

                continue;
            }

            $this->unknownKeys($path, $rule, ['enabled', 'severity'], $errors);
            $this->optionalBool($path . '.enabled', $rule['enabled'] ?? null, $errors);

            if (array_key_exists('severity', $rule)) {
                $this->severity($path . '.severity', $rule['severity'], $errors);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private function commentValues(mixed $value, array &$errors): void
    {
        if (!is_array($value) || array_is_list($value)) {
            return;
        }

        foreach ([
            'fail_on' => ['error', 'warning', 'info'],
            'fail_confidence' => ['low', 'medium', 'high'],
            'doc_mode' => ['heuristic', 'parser', 'hybrid'],
        ] as $key => $allowed) {
            if (array_key_exists($key, $value)) {
                $this->enum('comments.' . $key, $value[$key], $allowed, $errors);
            }
        }

        foreach (['doc_signature_consistency', 'doc_type_hygiene', 'explain', 'scan_markers'] as $key) {
            $this->optionalBool('comments.' . $key, $value[$key] ?? null, $errors);
        }

        foreach (['baseline', 'write_baseline'] as $key) {
            $this->optionalString('comments.' . $key, $value[$key] ?? null, $errors);
        }

        $this->stringList('comments.marker_tags', $value['marker_tags'] ?? null, $errors);
        $this->severityMap('comments.marker_severity', $value['marker_severity'] ?? null, $errors);
        $this->commentCustomRules($value['custom_rules'] ?? null, $errors);
        $this->commentDocCache($value['doc_cache'] ?? null, $errors);
        $this->commentRules($value['rules'] ?? null, $errors);
    }

    /**
     * @param list<string> $errors
     */
    private function duplicateCache(mixed $value, array &$errors): void
    {
        $this->cacheValues('duplicates.cache', $value, $errors);
    }

    /**
     * @param list<string> $errors
     */
    private function duplicateOutput(mixed $value, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[] = 'duplicates.output must be a JSON object.';

            return;
        }

        $this->unknownKeys('duplicates.output', $value, ['style', 'score_colors'], $errors);

        if (array_key_exists('style', $value)) {
            $this->enum('duplicates.output.style', $value['style'], ['compact', 'classic'], $errors);
        }

        $bands = $value['score_colors'] ?? null;

        if ($bands === null) {
            return;
        }

        if (!is_array($bands) || array_is_list($bands)) {
            $errors[] = 'duplicates.output.score_colors must be a JSON object.';

            return;
        }

        $this->unknownKeys('duplicates.output.score_colors', $bands, ['high', 'medium', 'low', 'base'], $errors);

        foreach (['high', 'medium', 'low', 'base'] as $band) {
            $entry = $bands[$band] ?? null;

            if ($entry === null) {
                continue;
            }

            if (!is_array($entry) || array_is_list($entry)) {
                $errors[] = sprintf('duplicates.output.score_colors.%s must be a JSON object.', $band);

                continue;
            }

            $allowed = $band === 'base' ? ['color'] : ['min', 'color'];
            $path = 'duplicates.output.score_colors.' . $band;
            $this->unknownKeys($path, $entry, $allowed, $errors);

            if ($band !== 'base' && array_key_exists('min', $entry)) {
                $this->number($path . '.min', $entry['min'], 0.0, null, $errors);
            }

            if (array_key_exists('color', $entry)) {
                $this->enum($path . '.color', $entry['color'], self::COLORS, $errors);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private function duplicateValues(mixed $value, array &$errors): void
    {
        if (!is_array($value) || array_is_list($value)) {
            return;
        }

        if (array_key_exists('mode', $value)) {
            $this->enum('duplicates.mode', $value['mode'], ['gate', 'audit'], $errors);
        }

        foreach (['normalize', 'fuzzy', 'near_miss'] as $key) {
            $this->optionalBool('duplicates.' . $key, $value[$key] ?? null, $errors);
        }

        foreach (['min_lines', 'min_tokens', 'min_statements', 'max_near_miss_comparisons'] as $key) {
            if (array_key_exists($key, $value)) {
                $this->integer('duplicates.' . $key, $value[$key], 1, $errors);
            }
        }

        if (is_int($value['max_near_miss_comparisons'] ?? null) && $value['max_near_miss_comparisons'] > 10_000_000) {
            $errors[] = 'duplicates.max_near_miss_comparisons must not exceed 10000000.';
        }

        if (array_key_exists('min_similarity', $value)) {
            $this->number('duplicates.min_similarity', $value['min_similarity'], 0.0, 1.0, $errors);
        }

        if (array_key_exists('error_duplicate_percentage', $value)) {
            $this->number('duplicates.error_duplicate_percentage', $value['error_duplicate_percentage'], 0.0, 100.0, $errors);
        }

        foreach (['baseline', 'write_baseline'] as $key) {
            $this->optionalString('duplicates.' . $key, $value[$key] ?? null, $errors);
        }

        $this->stringList('duplicates.ignore_fingerprints', $value['ignore_fingerprints'] ?? null, $errors);

        if (array_key_exists('fail_on', $value)) {
            $this->enum('duplicates.fail_on', $value['fail_on'], ['error', 'warning', 'info'], $errors);
        }

        $this->duplicateCache($value['cache'] ?? null, $errors);
        $this->duplicateOutput($value['output'] ?? null, $errors);
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private function enum(string $path, mixed $value, array $allowed, array &$errors): void
    {
        if (!is_string($value) || !in_array(strtolower(trim($value)), $allowed, true)) {
            $errors[] = sprintf('%s must be one of: %s.', $path, implode(', ', $allowed));
        }
    }

    /**
     * @param list<string> $errors
     */
    private function integer(string $path, mixed $value, int $minimum, array &$errors): void
    {
        if (!is_int($value) || $value < $minimum) {
            $errors[] = sprintf('%s must be an integer greater than or equal to %d.', $path, $minimum);
        }
    }

    /**
     * @param array<string, 'bool'|'string'|'strings'> $schema
     * @param list<string> $errors
     */
    private function nestedObject(string $path, mixed $value, array $schema, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $path . ' must be a JSON object.';

            return;
        }

        $this->unknownKeys($path, $value, array_keys($schema), $errors);

        foreach ($schema as $key => $type) {
            if ($type === 'bool') {
                $this->optionalBool($path . '.' . $key, $value[$key] ?? null, $errors);
            } elseif ($type === 'string') {
                $this->optionalString($path . '.' . $key, $value[$key] ?? null, $errors);
            } else {
                $this->stringList($path . '.' . $key, $value[$key] ?? null, $errors);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private function number(string $path, mixed $value, float $minimum, ?float $maximum, array &$errors): void
    {
        if (!is_int($value) && !is_float($value)) {
            $errors[] = sprintf('%s must be a number.', $path);

            return;
        }

        $number = (float) $value;

        if ($number < $minimum || ($maximum !== null && $number > $maximum)) {
            $range = $maximum === null ? sprintf('at least %s', $minimum) : sprintf('between %s and %s', $minimum, $maximum);
            $errors[] = sprintf('%s must be %s.', $path, $range);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function optionalBool(string $path, mixed $value, array &$errors): void
    {
        if ($value !== null && !is_bool($value)) {
            $errors[] = sprintf('%s must be a boolean.', $path);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function optionalString(string $path, mixed $value, array &$errors): void
    {
        if ($value !== null && !is_string($value)) {
            $errors[] = sprintf('%s must be a string.', $path);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function output(mixed $value, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[] = 'output must be a JSON object.';

            return;
        }

        $this->unknownKeys('output', $value, ['colors'], $errors);
        $colors = $value['colors'] ?? null;

        if ($colors === null) {
            return;
        }

        if (!is_array($colors) || array_is_list($colors)) {
            $errors[] = 'output.colors must be a JSON object.';

            return;
        }

        $this->unknownKeys('output.colors', $colors, ['success', 'error', 'warning', 'info', 'file'], $errors);

        foreach ($colors as $name => $color) {
            $this->enum('output.colors.' . $name, $color, self::COLORS, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function severity(string $path, mixed $value, array &$errors): void
    {
        $this->enum($path, $value, ['error', 'critical', 'high', 'warning', 'medium', 'low', 'info'], $errors);
    }

    /**
     * @param list<string> $errors
     */
    private function severityMap(string $path, mixed $value, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $path . ' must be a JSON object.';

            return;
        }

        foreach ($value as $name => $severity) {
            if (!is_string($name) || trim($name) === '') {
                $errors[] = $path . ' keys must be non-empty strings.';

                continue;
            }

            $this->severity($path . '.' . $name, $severity, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function stringList(string $path, mixed $value, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = sprintf('%s must be a list of strings.', $path);

            return;
        }

        foreach ($value as $index => $item) {
            if (!is_string($item) || trim($item) === '') {
                $errors[] = sprintf('%s[%d] must be a non-empty string.', $path, $index);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private function syntaxValues(mixed $value, array &$errors): void
    {
        if (!is_array($value) || array_is_list($value)) {
            return;
        }

        if (array_key_exists('parallel', $value)) {
            $this->integer('syntax.parallel', $value['parallel'], 1, $errors);

            if (is_int($value['parallel']) && $value['parallel'] > 64) {
                $errors[] = 'syntax.parallel must not exceed 64.';
            }
        }

        if (array_key_exists('timeout', $value)) {
            $this->number('syntax.timeout', $value['timeout'], 0.1, 600.0, $errors);
        }
    }

    /**
     * @param array<mixed, mixed> $value
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private function unknownKeys(string $path, array $value, array $allowed, array &$errors): void
    {
        foreach ($value as $key => $_item) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $errors[] = sprintf('%s.%s is not a supported key.', $path, (string) $key);
            }
        }
    }
}

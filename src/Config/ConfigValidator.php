<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

use Infocyph\PHPProbe\Util\ArrayShape;

final class ConfigValidator
{
    /**
     * @var array<string, string>
     */
    private const CHECKER_SECTION_COMMON_SCHEMA = [
        'paths' => 'list',
        'exclude' => 'list',
        'exclude_paths' => 'list',
        'format' => 'string',
        'json' => 'bool',
        'summary_json' => 'string',
        'changed_only' => 'bool',
        'changed_base' => 'string',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ENUM_VALUES = [
        'root.preset' => ['default', 'standard', 'ci', 'strict', 'phpstorm', 'legacy-standard'],
        'output.colors.success' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.error' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.warning' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.info' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.muted' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.file' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.severity.error' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.severity.critical' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.severity.high' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.severity.warning' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.severity.medium' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.severity.low' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'output.colors.severity.info' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'],
        'syntax.format' => ['text', 'json', 'markdown', 'sarif', 'github'],
        'duplicates.mode' => ['gate', 'audit'],
        'duplicates.output.style' => ['compact', 'classic'],
        'duplicates.output.score_colors.high.color' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray'],
        'duplicates.output.score_colors.medium.color' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray'],
        'duplicates.output.score_colors.low.color' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray'],
        'duplicates.output.score_colors.base.color' => ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray'],
        'duplicates.format' => ['text', 'json', 'markdown', 'sarif', 'github'],
        'duplicates.fail_on' => ['error', 'warning', 'info'],
        'api.format' => ['text', 'json', 'markdown', 'sarif', 'github'],
        'api.fail_on' => ['error', 'warning', 'info'],
        'comments.format' => ['text', 'json', 'markdown', 'sarif', 'github'],
        'comments.fail_on' => ['error', 'warning', 'info'],
        'comments.doc_mode' => ['heuristic', 'parser', 'hybrid'],
        'comments.fail_confidence' => ['low', 'medium', 'high'],
        'commented_out_code.policy' => ['relaxed', 'standard', 'strict'],
    ];

    /**
     * @return list<string>
     */
    public function validateFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Config file not found: %s', $path));
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('Config file is not readable: %s', $path));
        }

        $contents = file_get_contents($path);

        if (!is_string($contents) || trim($contents) === '') {
            throw new \RuntimeException(sprintf('Config file is empty: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Invalid config JSON at %s: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            return ['Config root must be a JSON object.'];
        }

        $root = ArrayShape::stringKeyed($decoded);
        $errors = [];
        $this->validateRoot($root, $errors);

        return $errors;
    }

    /**
     * @param array<string, string> $sectionSchema
     * @return array<string, string>
     */
    private function checkerSchema(array $sectionSchema): array
    {
        return [
            ...self::CHECKER_SECTION_COMMON_SCHEMA,
            ...$sectionSchema,
        ];
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'bool' => is_bool($value),
            'string' => is_string($value),
            'int' => is_int($value),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'list' => is_string($value) || (is_array($value) && array_is_list($value)),
            default => false,
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function sectionSchemas(): array
    {
        return [
            'output' => [
                'colors' => 'object',
            ],
            'syntax' => $this->checkerSchema([
                'parallel' => 'int',
            ]),
            'duplicates' => $this->checkerSchema([
                'mode' => 'string',
                'normalize' => 'bool',
                'fuzzy' => 'bool',
                'near_miss' => 'bool',
                'min_lines' => 'number',
                'min_tokens' => 'number',
                'min_statements' => 'number',
                'min_similarity' => 'number',
                'baseline' => 'string',
                'write_baseline' => 'string',
                'fail_on' => 'string',
                'ignore_fingerprints' => 'list',
                'cache' => 'object',
                'output' => 'object',
            ]),
            'api' => $this->checkerSchema([
                'include_protected' => 'bool',
                'baseline' => 'string',
                'write_baseline' => 'string',
                'fail_on' => 'string',
            ]),
            'comments' => $this->checkerSchema([
                'scan_markers' => 'bool',
                'doc_mode' => 'string',
                'fail_confidence' => 'string',
                'explain' => 'bool',
                'doc_signature_consistency' => 'bool',
                'doc_type_hygiene' => 'bool',
                'baseline' => 'string',
                'write_baseline' => 'string',
                'marker_tags' => 'list',
                'marker_severity' => 'object',
                'rules' => 'object',
                'custom_rules' => 'list',
                'doc_cache' => 'object',
                'fail_on' => 'string',
            ]),
            'commented_out_code' => [
                'enabled' => 'bool',
                'policy' => 'string',
                'min_reason_length' => 'number',
                'max_allowed_block_lines' => 'number',
                'require_issue_for_blocks_longer_than' => 'number',
                'allowed_reason_tags' => 'list',
                'optional_reason_tags' => 'list',
                'ignore_paths' => 'list',
                'allow_optional_reason_tags_in_strict_mode' => 'bool',
                'allowed_issue_patterns' => 'list',
                'suppression' => 'object',
                'single_line_comments' => 'object',
                'block_comments' => 'object',
                'phpdoc_comments' => 'object',
                'finding_severity' => 'object',
                'finding_severity_strict' => 'object',
            ],
        ];
    }

    private function typeLabel(string $type): string
    {
        /** @var array<string, string> $labels */
        $labels = [
            'bool' => 'a boolean',
            'string' => 'a string',
            'int' => 'an integer',
            'number' => 'a number',
            'object' => 'an object',
            'list' => 'a string or list of strings',
        ];

        return $labels[$type] ?? $type;
    }

    /**
     * @param array<string, mixed> $comments
     * @param list<string> $errors
     */
    private function validateCommentCustomRules(array $comments, array &$errors): void
    {
        $rules = $comments['custom_rules'] ?? null;

        if ($rules === null) {
            return;
        }

        if (!is_array($rules) || !array_is_list($rules)) {
            $errors[] = 'comments.custom_rules must be a list.';

            return;
        }

        foreach ($rules as $index => $rule) {
            if (!is_array($rule) || array_is_list($rule)) {
                $errors[] = sprintf('comments.custom_rules[%d] must be an object.', $index);

                continue;
            }

            $entry = ArrayShape::stringKeyed($rule);

            if (!is_string($entry['id'] ?? null) || trim($entry['id']) === '') {
                $errors[] = sprintf('comments.custom_rules[%d].id must be a non-empty string.', $index);
            }

            if (!is_string($entry['pattern'] ?? null) || trim($entry['pattern']) === '') {
                $errors[] = sprintf('comments.custom_rules[%d].pattern must be a non-empty string.', $index);
            }

            if (isset($entry['severity']) && (!is_string($entry['severity']) || !in_array(strtolower(trim($entry['severity'])), ['error', 'critical', 'high', 'warning', 'medium', 'low', 'info'], true))) {
                $errors[] = sprintf('comments.custom_rules[%d].severity must be one of: error, critical, high, warning, medium, low, info.', $index);
            }

            if (isset($entry['scope']) && (!is_string($entry['scope']) || !in_array(strtolower(trim($entry['scope'])), ['all', 'line', 'block', 'doc'], true))) {
                $errors[] = sprintf('comments.custom_rules[%d].scope must be one of: all, line, block, doc.', $index);
            }

            if (isset($entry['enabled']) && !is_bool($entry['enabled'])) {
                $errors[] = sprintf('comments.custom_rules[%d].enabled must be a boolean.', $index);
            }
        }
    }

    /**
     * @param array<string, mixed> $comments
     * @param list<string> $errors
     */
    private function validateCommentDocCache(array $comments, array &$errors): void
    {
        $cache = $comments['doc_cache'] ?? null;

        if ($cache === null) {
            return;
        }

        if (!is_array($cache) || array_is_list($cache)) {
            $errors[] = 'comments.doc_cache must be an object.';

            return;
        }

        $entry = ArrayShape::stringKeyed($cache);
        $allowed = ['enabled', 'file'];
        $this->validateUnknownKeys('comments.doc_cache', $entry, $allowed, $errors);

        if (isset($entry['enabled']) && !is_bool($entry['enabled'])) {
            $errors[] = 'comments.doc_cache.enabled must be a boolean.';
        }

        if (isset($entry['file']) && !is_string($entry['file'])) {
            $errors[] = 'comments.doc_cache.file must be a string.';
        }
    }

    /**
     * @param array<string, mixed> $duplicates
     * @param list<string> $errors
     */
    private function validateDuplicateOutput(array $duplicates, array &$errors): void
    {
        $output = $duplicates['output'] ?? null;

        if ($output === null) {
            return;
        }

        if (!is_array($output) || array_is_list($output)) {
            $errors[] = 'duplicates.output must be an object.';

            return;
        }

        $outputObject = ArrayShape::stringKeyed($output);
        $this->validateUnknownKeys('duplicates.output', $outputObject, ['style', 'score_colors'], $errors);

        if (isset($outputObject['style'])) {
            $this->validateEnumValue('duplicates.output', 'style', $outputObject['style'], $errors);
        }

        $scoreColors = $outputObject['score_colors'] ?? null;

        if ($scoreColors === null) {
            return;
        }

        if (!is_array($scoreColors) || array_is_list($scoreColors)) {
            $errors[] = 'duplicates.output.score_colors must be an object.';

            return;
        }

        $scoreColorsObject = ArrayShape::stringKeyed($scoreColors);
        $this->validateUnknownKeys('duplicates.output.score_colors', $scoreColorsObject, ['high', 'medium', 'low', 'base'], $errors);

        foreach (['high', 'medium', 'low', 'base'] as $band) {
            $entry = $scoreColorsObject[$band] ?? null;

            if ($entry === null) {
                continue;
            }

            if (!is_array($entry) || array_is_list($entry)) {
                $errors[] = sprintf('duplicates.output.score_colors.%s must be an object.', $band);

                continue;
            }

            $entryObject = ArrayShape::stringKeyed($entry);
            $allowed = $band === 'base' ? ['color'] : ['min', 'color'];
            $this->validateUnknownKeys(sprintf('duplicates.output.score_colors.%s', $band), $entryObject, $allowed, $errors);

            if ($band !== 'base' && isset($entryObject['min']) && !$this->matchesType($entryObject['min'], 'number')) {
                $errors[] = sprintf('duplicates.output.score_colors.%s.min must be a number.', $band);
            }

            if (isset($entryObject['color'])) {
                $this->validateEnumValue(sprintf('duplicates.output.score_colors.%s', $band), 'color', $entryObject['color'], $errors);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private function validateEnumValue(string $section, string $key, mixed $value, array &$errors): void
    {
        if (!is_string($value)) {
            return;
        }

        $enumKey = $section . '.' . $key;
        $allowed = self::ENUM_VALUES[$enumKey] ?? null;

        if (!is_array($allowed)) {
            return;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, $allowed, true)) {
            return;
        }

        $errors[] = sprintf(
            '%s.%s must be one of: %s.',
            $section,
            $key,
            implode(', ', $allowed),
        );
    }

    /**
     * @param array<string, mixed> $root
     * @param list<string> $errors
     */
    private function validateRoot(array $root, array &$errors): void
    {
        $allowed = ['preset', 'output', 'syntax', 'duplicates', 'api', 'comments', 'commented_out_code'];
        $this->validateUnknownKeys('root', $root, $allowed, $errors);

        if (isset($root['preset']) && !is_string($root['preset'])) {
            $errors[] = 'root.preset must be a string.';
        } elseif (is_string($root['preset'] ?? null)) {
            $this->validateEnumValue('root', 'preset', $root['preset'], $errors);
        }

        foreach ($this->sectionSchemas() as $name => $schema) {
            $this->validateSection($name, $root[$name] ?? null, $schema, $errors);
        }

        $this->validateCommentCustomRules(ArrayShape::stringKeyed($root['comments'] ?? []), $errors);
        $this->validateCommentDocCache(ArrayShape::stringKeyed($root['comments'] ?? []), $errors);
        $this->validateDuplicateOutput(ArrayShape::stringKeyed($root['duplicates'] ?? []), $errors);
        $this->validateOutput(ArrayShape::stringKeyed($root['output'] ?? []), $errors);
    }

    /**
     * @param array<string, mixed> $output
     * @param list<string> $errors
     */
    private function validateOutput(array $output, array &$errors): void
    {
        if ($output === []) {
            return;
        }

        $this->validateUnknownKeys('output', $output, ['colors'], $errors);
        $colors = $output['colors'] ?? null;

        if ($colors === null) {
            return;
        }

        if (!is_array($colors) || array_is_list($colors)) {
            $errors[] = 'output.colors must be an object.';

            return;
        }

        $colorsObject = ArrayShape::stringKeyed($colors);
        $this->validateUnknownKeys('output.colors', $colorsObject, ['success', 'error', 'warning', 'info', 'muted', 'file', 'severity'], $errors);

        foreach (['success', 'error', 'warning', 'info', 'muted', 'file'] as $name) {
            if (!array_key_exists($name, $colorsObject)) {
                continue;
            }

            $this->validateEnumValue('output.colors', $name, $colorsObject[$name], $errors);
        }

        $severity = $colorsObject['severity'] ?? null;

        if ($severity === null) {
            return;
        }

        if (!is_array($severity) || array_is_list($severity)) {
            $errors[] = 'output.colors.severity must be an object.';

            return;
        }

        $severityObject = ArrayShape::stringKeyed($severity);
        $this->validateUnknownKeys('output.colors.severity', $severityObject, ['error', 'critical', 'high', 'warning', 'medium', 'low', 'info'], $errors);

        foreach ($severityObject as $name => $value) {
            $this->validateEnumValue('output.colors.severity', $name, $value, $errors);
        }
    }

    /**
     * @param array<string, string> $schema
     * @param list<string> $errors
     */
    private function validateSection(string $name, mixed $value, array $schema, array &$errors): void
    {
        if ($value === null) {
            return;
        }

        if (!is_array($value)) {
            $errors[] = sprintf('%s must be a JSON object.', $name);

            return;
        }

        $section = ArrayShape::stringKeyed($value);
        $this->validateUnknownKeys($name, $section, array_keys($schema), $errors);

        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $section)) {
                continue;
            }

            if (!$this->matchesType($section[$key], $type)) {
                $errors[] = sprintf('%s.%s must be %s.', $name, $key, $this->typeLabel($type));

                continue;
            }

            $this->validateEnumValue($name, $key, $section[$key], $errors);
        }
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private function validateUnknownKeys(string $name, array $section, array $allowed, array &$errors): void
    {
        foreach ($section as $key => $_value) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = sprintf('%s.%s is not a supported key.', $name, $key);
            }
        }
    }
}

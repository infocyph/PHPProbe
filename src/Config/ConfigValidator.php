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
     * @param array<string, mixed> $root
     * @param list<string> $errors
     */
    private function validateRoot(array $root, array &$errors): void
    {
        $allowed = ['preset', 'syntax', 'duplicates', 'api', 'comments', 'commented_out_code'];
        $this->validateUnknownKeys('root', $root, $allowed, $errors);

        if (isset($root['preset']) && !is_string($root['preset'])) {
            $errors[] = 'root.preset must be a string.';
        }

        foreach ($this->sectionSchemas() as $name => $schema) {
            $this->validateSection($name, $root[$name] ?? null, $schema, $errors);
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function sectionSchemas(): array
    {
        return [
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
            ]),
            'api' => $this->checkerSchema([
                'include_protected' => 'bool',
                'baseline' => 'string',
                'write_baseline' => 'string',
                'fail_on' => 'string',
            ]),
            'comments' => $this->checkerSchema([
                'scan_markers' => 'bool',
                'marker_tags' => 'list',
                'marker_severity' => 'object',
                'rules' => 'object',
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
            }
        }
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
}

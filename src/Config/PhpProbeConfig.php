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
        $errors = (new ConfigValidator())->validate($config);

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
    public function applyDuplicateOptions(array $options): array
    {
        $section = $this->section('duplicates');
        $options = $this->applyCommon($options, $section);
        $map = [
            'mode' => 'mode',
            'normalize' => 'normalize',
            'fuzzy' => 'fuzzy',
            'near_miss' => 'nearMiss',
            'min_lines' => 'minLines',
            'min_tokens' => 'minTokens',
            'min_statements' => 'minStatements',
            'min_similarity' => 'minSimilarity',
            'max_near_miss_comparisons' => 'maxNearMissComparisons',
            'baseline' => 'baseline',
            'write_baseline' => 'writeBaseline',
            'ignore_fingerprints' => 'ignoreFingerprints',
            'fail_on' => 'failOn',
            'error_duplicate_percentage' => 'errorDuplicatePercentage',
        ];

        foreach ($map as $key => $option) {
            if (array_key_exists($key, $section)) {
                $options[$option] = $section[$key];
            }
        }

        $cache = $this->object($section['cache'] ?? null);

        if (array_key_exists('enabled', $cache)) {
            $options['cacheEnabled'] = $cache['enabled'];
        }

        if (is_string($cache['file'] ?? null) && $cache['file'] !== '') {
            $options['cacheFile'] = $cache['file'];
        }

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

    /**
     * @return array<string, mixed>
     */
    private function section(string $name): array
    {
        return $this->object($this->config[$name] ?? null);
    }
}

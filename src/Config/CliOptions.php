<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final readonly class CliOptions
{
    /**
     * @param list<string> $args
     * @param array<string, mixed> $options
     * @param callable(string,int,array<string,mixed>):bool $parseCliOption
     */
    public function collectPaths(array $args, array &$options, array $configuredPaths, callable $parseCliOption, string $unknownOptionMessage): void
    {
        $options['paths'] = [];
        $collectingPathsOnly = false;
        $index = 0;
        $argCount = count($args);

        while ($index < $argCount) {
            $arg = $args[$index];

            if ($collectingPathsOnly) {
                $options['paths'][] = $arg;
                $index++;

                continue;
            }

            if ($arg === '--') {
                $collectingPathsOnly = true;
                $index++;

                continue;
            }

            if ($this->skipConfig($args, $index, $arg) || $this->skipPreset($args, $index, $arg)) {
                $index++;

                continue;
            }

            if ($parseCliOption($arg, $index, $options)) {
                $index++;

                continue;
            }

            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException(sprintf($unknownOptionMessage, $arg));
            }

            $options['paths'][] = $arg;
            $index++;
        }

        if ($options['paths'] === []) {
            $options['paths'] = $configuredPaths;
        }
    }

    /**
     * @param list<string> $args
     */
    public function configPath(array $args, string $default): string
    {
        return $this->valuedOption($args, '--config') ?? $default;
    }

    /**
     * @param list<string> $allowed
     */
    public function isAllowedFormat(string $format, array $allowed = ['text', 'json', 'markdown', 'sarif', 'github']): bool
    {
        return in_array(strtolower(trim($format)), $allowed, true);
    }

    public function mergeConfigWithPreset(PhpProbeConfig $config, string $cliPreset): PhpProbeConfig
    {
        $repository = new PresetRepository();
        $configPreset = $config->preset();

        if (is_string($configPreset) && $configPreset !== '') {
            $config = $repository->config($configPreset)->merge($config);
        }

        return $cliPreset !== '' ? $config->merge($repository->config($cliPreset)) : $config;
    }

    /**
     * @param list<string> $allowed
     */
    public function normalizeFormat(string $format, array $allowed = ['text', 'json', 'markdown', 'sarif', 'github']): string
    {
        $normalized = strtolower(trim($format));

        if (!$this->isAllowedFormat($normalized, $allowed)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid --format value "%s". Expected one of: %s.',
                $format,
                implode(', ', $allowed),
            ));
        }

        return $normalized;
    }

    public function optionValue(string $arg, string $name): ?string
    {
        return str_starts_with($arg, $name . '=') ? substr($arg, strlen($name) + 1) : null;
    }

    /**
     * @param array{changedOnly:bool,changedBase:string} $options
     */
    public function parseChangedOptions(array &$options, string $arg): bool
    {
        if ($arg === '--changed-only') {
            $options['changedOnly'] = true;

            return true;
        }

        return $this->parseTrimmedOption($options, $arg, '--changed-base', 'changedBase');
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $options
     */
    public function parseCommonCheckerOptions(
        array $args,
        int &$index,
        array &$options,
        string $arg,
        bool $includeFailOn,
    ): bool {
        if ($this->parseExclude($args, $index, $options, $arg)) {
            return true;
        }

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;

            return true;
        }

        if ($this->parseOutputFormat($options, $arg)) {
            return true;
        }

        if ($includeFailOn && $this->parseFailOn($options, $arg)) {
            return true;
        }

        if ($this->parseSummaryJson($options, $arg)) {
            return true;
        }

        return $this->parseChangedOptions($options, $arg);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $allowed
     */
    public function parseEnumOption(
        array &$options,
        string $arg,
        string $name,
        string $targetKey,
        array $allowed,
        string $errorMessage,
    ): bool {
        $value = $this->optionValue($arg, $name);

        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim($value));

        if (!in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf($errorMessage, $value));
        }

        $options[$targetKey] = $normalized;

        return true;
    }

    /**
     * @param list<string> $args
     * @param array{excludes:list<string>} $options
     */
    public function parseExclude(array $args, int &$index, array &$options, string $arg): bool
    {
        return $this->parseRepeatableValue($args, $index, $options['excludes'], '--exclude', $arg);
    }

    /**
     * @param array{failOn:string} $options
     */
    public function parseFailOn(array &$options, string $arg): bool
    {
        return $this->parseEnumOption(
            $options,
            $arg,
            '--fail-on',
            'failOn',
            ['error', 'warning', 'info'],
            'Invalid --fail-on value "%s". Expected: error, warning, info.',
        );
    }

    /**
     * @param array{format:string} $options
     * @param list<string> $allowed
     */
    public function parseOutputFormat(array &$options, string $arg, array $allowed = ['text', 'json', 'markdown', 'sarif', 'github']): bool
    {
        if ($arg === '--json') {
            $options['format'] = 'json';

            return true;
        }

        $format = $this->optionValue($arg, '--format');

        if ($format === null) {
            return false;
        }

        $options['format'] = $this->normalizeFormat($format, $allowed);

        return true;
    }

    /**
     * @param list<string> $args
     * @param list<string> $target
     */
    public function parseRepeatableValue(array $args, int &$index, array &$target, string $name, string $arg): bool
    {
        $value = $this->optionValue($arg, $name);

        if ($value !== null) {
            if ($value !== '') {
                $target[] = $value;
                $target = array_values(array_unique($target));
            }

            return true;
        }

        if ($arg !== $name) {
            return false;
        }

        if (isset($args[$index + 1]) && $args[$index + 1] !== '') {
            $target[] = $args[++$index];
            $target = array_values(array_unique($target));
        }

        return true;
    }

    /**
     * @param array{baseline:string,writeBaseline:string} $options
     */
    public function parseSnapshotFileOptions(array &$options, string $arg, string $defaultWritePath): bool
    {
        $baseline = $this->optionValue($arg, '--baseline');

        if ($baseline !== null) {
            $options['baseline'] = $baseline;

            return true;
        }

        $writeBaseline = $this->optionValue($arg, '--write-baseline');

        if ($writeBaseline !== null || $arg === '--write-baseline') {
            $options['writeBaseline'] = $writeBaseline !== null && $writeBaseline !== '' ? $writeBaseline : $defaultWritePath;

            return true;
        }

        return false;
    }

    /**
     * @param array{summaryJson:string} $options
     */
    public function parseSummaryJson(array &$options, string $arg): bool
    {
        return $this->parseTrimmedOption($options, $arg, '--summary-json', 'summaryJson');
    }

    /**
     * @param list<string> $args
     */
    public function presetName(array $args): string
    {
        return $this->valuedOption($args, '--preset') ?? '';
    }

    /**
     * @param list<string> $args
     */
    public function skipConfig(array $args, int &$index, string $arg): bool
    {
        return $this->skipValuedOption($args, $index, $arg, '--config');
    }

    /**
     * @param list<string> $args
     */
    public function skipPreset(array $args, int &$index, string $arg): bool
    {
        return $this->skipValuedOption($args, $index, $arg, '--preset');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function parseTrimmedOption(array &$options, string $arg, string $name, string $targetKey): bool
    {
        $value = $this->optionValue($arg, $name);

        if ($value === null) {
            return false;
        }

        $options[$targetKey] = trim($value);

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function skipValuedOption(array $args, int &$index, string $arg, string $name): bool
    {
        if ($this->optionValue($arg, $name) !== null) {
            return true;
        }

        if ($arg !== $name) {
            return false;
        }

        if (isset($args[$index + 1])) {
            $index++;
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function valuedOption(array $args, string $name): ?string
    {
        $argCount = count($args);

        for ($index = 0; $index < $argCount; $index++) {
            $value = $this->optionValue($args[$index], $name);

            if ($value !== null) {
                return $value;
            }

            if ($args[$index] === $name && isset($args[$index + 1])) {
                return $args[$index + 1];
            }
        }

        return null;
    }
}

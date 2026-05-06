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
     * @param list<string> $allowed
     */
    public function isAllowedFormat(string $format, array $allowed = ['text', 'json', 'markdown', 'sarif']): bool
    {
        return in_array(strtolower(trim($format)), $allowed, true);
    }

    /**
     * @param list<string> $allowed
     */
    public function normalizeFormat(string $format, array $allowed = ['text', 'json', 'markdown', 'sarif']): string
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

    /**
     * @param list<string> $args
     */
    public function configPath(array $args, string $default): string
    {
        return $this->valuedOption($args, '--config') ?? $default;
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

        $changedBase = $this->optionValue($arg, '--changed-base');

        if ($changedBase === null) {
            return false;
        }

        $options['changedBase'] = trim($changedBase);

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
        $summaryJson = $this->optionValue($arg, '--summary-json');

        if ($summaryJson === null) {
            return false;
        }

        $options['summaryJson'] = trim($summaryJson);

        return true;
    }

    /**
     * @param array{format:string} $options
     * @param list<string> $allowed
     */
    public function parseOutputFormat(array &$options, string $arg, array $allowed = ['text', 'json', 'markdown', 'sarif']): bool
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
     * @param array{failOn:string} $options
     */
    public function parseFailOn(array &$options, string $arg): bool
    {
        $failOn = $this->optionValue($arg, '--fail-on');

        if ($failOn === null) {
            return false;
        }

        $normalized = strtolower(trim($failOn));

        if (!in_array($normalized, ['error', 'warning', 'info'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid --fail-on value "%s". Expected: error, warning, info.',
                $failOn,
            ));
        }

        $options['failOn'] = $normalized;

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

<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final readonly class CliOptions
{
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
        for ($index = 0; $index < count($args); $index++) {
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

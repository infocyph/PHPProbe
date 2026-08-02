<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final readonly class PresetRepository
{
    /** @var non-empty-list<string> */
    public const array NAMES = ['default', 'standard', 'ci', 'strict'];

    public function config(string $name): PhpProbeConfig
    {
        $normalized = $this->normalize($name);
        $default = PhpProbeConfig::fromFile(Paths::preset('default'));

        if ($normalized === 'default') {
            return $default;
        }

        $selected = PhpProbeConfig::fromFile(Paths::preset($normalized));

        return $default->merge($selected);
    }

    public function json(string $name): string
    {
        $path = Paths::preset($this->normalize($name));
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Failed to read PHPProbe preset: %s', $path));
        }

        return $contents;
    }

    /**
     * @return non-empty-list<string>
     */
    public function names(): array
    {
        return self::NAMES;
    }

    private function normalize(string $name): string
    {
        $normalized = strtolower(trim($name));

        if (!in_array($normalized, self::NAMES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown PHPProbe preset "%s". Available presets: %s.',
                $name,
                implode(', ', self::NAMES),
            ));
        }

        return $normalized;
    }
}

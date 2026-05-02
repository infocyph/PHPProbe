<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final readonly class PresetRepository
{
    /**
     * @return non-empty-list<string>
     */
    public function names(): array
    {
        return ['phpstorm', 'standard', 'strict'];
    }

    public function config(string $name): PhpProbeConfig
    {
        return PhpProbeConfig::fromFile(Paths::preset($this->normalize($name)));
    }

    public function json(string $name): string
    {
        $path = Paths::preset($this->normalize($name));
        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : '{}';
    }

    private function normalize(string $name): string
    {
        $normalized = strtolower(trim($name));

        if (!in_array($normalized, $this->names(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown PHPProbe preset "%s". Available presets: %s.',
                $name,
                implode(', ', $this->names()),
            ));
        }

        return $normalized;
    }
}

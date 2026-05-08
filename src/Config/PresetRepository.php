<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final readonly class PresetRepository
{
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

    /**
     * @return non-empty-list<string>
     */
    public function names(): array
    {
        return ['default', 'standard', 'ci', 'strict'];
    }

    private function normalize(string $name): string
    {
        $normalized = strtolower(trim($name));
        $aliases = [
            'phpstorm' => 'standard',
            'legacy-standard' => 'ci',
        ];

        if (isset($aliases[$normalized])) {
            $normalized = $aliases[$normalized];
        }

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

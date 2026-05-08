<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class BaselineJson
{
    /**
     * @return array<string, true>
     */
    public static function knownFingerprints(string $path, string $context, string $collection): array
    {
        $decoded = self::readObject($path, $context);
        $items = $decoded[$collection] ?? null;

        if (!is_array($items)) {
            throw new \RuntimeException(sprintf('%s baseline is missing a valid "%s" array: %s', $context, $collection, $path));
        }

        $known = [];

        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['fingerprint'] ?? null)) {
                continue;
            }

            $known[$item['fingerprint']] = true;
        }

        return $known;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readObject(string $path, string $context): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('%s baseline file not found: %s', $context, $path));
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('%s baseline file is not readable: %s', $context, $path));
        }

        $contents = file_get_contents($path);
        $label = strtoupper($context) === 'API' ? 'API' : strtolower($context);

        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Failed to read %s baseline file: %s', $label, $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Invalid %s baseline JSON at %s: %s', $label, $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('%s baseline payload must be a JSON object: %s', $context, $path));
        }

        $trimmed = ltrim($contents);

        if ($trimmed !== '' && str_starts_with($trimmed, '[')) {
            throw new \RuntimeException(sprintf('%s baseline payload must be a JSON object: %s', $context, $path));
        }

        return ArrayShape::stringKeyed($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function writeObject(string $path, array $payload, string $context): void
    {
        $label = strtoupper($context) === 'API' ? 'API' : strtolower($context);

        try {
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Could not encode %s baseline JSON for %s: %s', $label, $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        AtomicFileWriter::write($path, $encoded);
    }
}

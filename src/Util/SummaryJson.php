<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class SummaryJson
{
    /**
     * @param array<string, mixed> $summary
     */
    public static function write(string $path, array $summary): void
    {
        ksort($summary);

        try {
            $encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Could not encode summary JSON for %s: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException(sprintf('Failed to write summary JSON file: %s', $path));
        }
    }
}


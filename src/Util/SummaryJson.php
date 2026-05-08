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

        AtomicFileWriter::write($path, $encoded);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function writeCheckerSummary(
        string $path,
        string $checker,
        int $exitCode,
        string $failOn,
        array $details,
    ): void {
        self::writeIfConfigured($path, [
            'checker' => $checker,
            'exit_code' => $exitCode,
            'fail_on' => $failOn,
            ...$details,
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     */
    public static function writeIfConfigured(string $path, array $summary): void
    {
        if ($path === '') {
            return;
        }

        self::write($path, $summary);
    }
}

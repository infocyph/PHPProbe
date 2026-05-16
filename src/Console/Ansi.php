<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

final class Ansi
{
    /**
     * @return list<string>
     */
    public static function supportedColors(): array
    {
        return ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'gray', 'bold'];
    }

    public static function color(string $text, string $name, mixed $stream): string
    {
        if (!self::enabled($stream)) {
            return $text;
        }

        $codes = [
            'red' => '31',
            'green' => '32',
            'yellow' => '33',
            'blue' => '34',
            'magenta' => '35',
            'cyan' => '36',
            'gray' => '90',
            'bold' => '1',
        ];

        $code = $codes[$name] ?? null;

        if (!is_string($code)) {
            return $text;
        }

        return "\033[" . $code . 'm' . $text . "\033[0m";
    }

    /**
     * @param array<string, string> $colors
     */
    public static function severity(string $severity, mixed $stream, array $colors = []): string
    {
        $normalized = strtolower($severity);
        $fallback = match ($normalized) {
            'error', 'critical', 'high' => 'red',
            'warning', 'medium' => 'yellow',
            'low' => 'blue',
            default => 'gray',
        };

        $color = $colors[$normalized] ?? $fallback;

        if (!in_array($color, self::supportedColors(), true)) {
            $color = $fallback;
        }

        return self::color(strtoupper($severity), $color, $stream);
    }

    private static function enabled(mixed $stream): bool
    {
        $noColor = getenv('NO_COLOR');

        if (is_string($noColor) && $noColor !== '') {
            return false;
        }

        $term = getenv('TERM');

        if (is_string($term) && strtolower($term) === 'dumb') {
            return false;
        }

        if (!is_resource($stream)) {
            return false;
        }

        return function_exists('stream_isatty') && stream_isatty($stream);
    }
}

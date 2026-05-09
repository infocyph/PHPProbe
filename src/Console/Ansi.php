<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

final class Ansi
{
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

    public static function severity(string $severity, mixed $stream): string
    {
        return match (strtolower($severity)) {
            'error', 'critical', 'high' => self::color(strtoupper($severity), 'red', $stream),
            'warning', 'medium' => self::color(strtoupper($severity), 'yellow', $stream),
            'low' => self::color(strtoupper($severity), 'blue', $stream),
            default => self::color(strtoupper($severity), 'gray', $stream),
        };
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

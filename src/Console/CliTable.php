<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

final class CliTable
{
    /**
     * @param non-empty-list<string> $headers
     * @param list<list<string|int|float>> $rows
     * @param array<int, int> $maximumWidths
     */
    public static function render(array $headers, array $rows, array $maximumWidths = []): string
    {
        $normalizedRows = array_map(
            static fn(array $row): array => array_map(static fn(string|int|float $cell): string => (string) $cell, $row),
            $rows,
        );
        $widths = [];

        foreach ($headers as $column => $header) {
            $width = self::visibleLength($header);

            foreach ($normalizedRows as $row) {
                $width = max($width, self::visibleLength($row[$column] ?? ''));
            }

            $widths[$column] = isset($maximumWidths[$column])
                ? min($width, max(self::visibleLength($header), $maximumWidths[$column]))
                : $width;
        }

        $separator = '+';

        foreach ($widths as $width) {
            $separator .= str_repeat('-', $width + 2) . '+';
        }

        $lines = [$separator, self::row($headers, $widths), $separator];

        foreach ($normalizedRows as $row) {
            $wrapped = [];
            $height = 1;

            foreach ($widths as $column => $width) {
                $wrapped[$column] = self::wrap($row[$column] ?? '', $width);
                $height = max($height, count($wrapped[$column]));
            }

            for ($line = 0; $line < $height; $line++) {
                $cells = [];

                foreach ($widths as $column => $_width) {
                    $cells[] = $wrapped[$column][$line] ?? '';
                }

                $lines[] = self::row($cells, $widths);
            }
        }

        $lines[] = $separator;

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     */
    private static function row(array $cells, array $widths): string
    {
        $line = '|';

        foreach ($widths as $column => $width) {
            $cell = $cells[$column] ?? '';
            $line .= ' ' . $cell . str_repeat(' ', max(0, $width - self::visibleLength($cell))) . ' |';
        }

        return $line;
    }

    private static function visibleLength(string $value): int
    {
        $plain = preg_replace('/\x1B\[[0-9;]*m/', '', $value) ?? $value;

        return function_exists('mb_strwidth') ? mb_strwidth($plain) : strlen($plain);
    }

    /** @return non-empty-list<string> */
    private static function wrap(string $value, int $width): array
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if ($value === '' || self::visibleLength($value) <= $width || str_contains($value, "\033[")) {
            return [$value];
        }

        $lines = [];
        $remaining = $value;

        while (strlen($remaining) > $width) {
            $candidate = substr($remaining, 0, $width + 1);
            $break = strrpos($candidate, ' ');

            if ($break === false || $break === 0) {
                $break = $width;
            }

            $lines[] = rtrim(substr($remaining, 0, $break));
            $remaining = ltrim(substr($remaining, $break));
        }

        $lines[] = $remaining;

        return $lines;
    }
}

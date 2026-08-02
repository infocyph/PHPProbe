<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final class OptionValues
{
    /** @param array<string, mixed> $options */
    public static function bool(array $options, string $key): bool
    {
        $value = $options[$key] ?? null;

        if (!is_bool($value)) {
            throw self::invalid($key, 'a boolean');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, 'bool'|'float'|'int'|'string'|'strings'> $schema
     * @return array<string, bool|float|int|string|list<string>>
     */
    public static function coerce(array $options, array $schema): array
    {
        $typed = [];

        foreach ($schema as $key => $type) {
            $typed[$key] = match ($type) {
                'bool' => self::bool($options, $key),
                'float' => self::float($options, $key),
                'int' => self::int($options, $key),
                'string' => self::string($options, $key),
                'strings' => self::strings($options, $key),
            };
        }

        return $typed;
    }

    /** @param array<string, mixed> $options */
    public static function float(array $options, string $key): float
    {
        $value = $options[$key] ?? null;

        if (!is_int($value) && !is_float($value)) {
            throw self::invalid($key, 'a number');
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $options */
    public static function int(array $options, string $key): int
    {
        $value = $options[$key] ?? null;

        if (!is_int($value)) {
            throw self::invalid($key, 'an integer');
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    public static function string(array $options, string $key): string
    {
        $value = $options[$key] ?? null;

        if (!is_string($value)) {
            throw self::invalid($key, 'a string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public static function strings(array $options, string $key): array
    {
        $value = $options[$key] ?? null;

        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($key, 'a list of strings');
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw self::invalid($key, 'a list of strings');
            }
        }

        return $value;
    }

    private static function invalid(string $key, string $expected): \LogicException
    {
        return new \LogicException(sprintf('Option "%s" must be %s.', $key, $expected));
    }
}

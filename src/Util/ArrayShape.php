<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class ArrayShape
{
    /**
     * @return array<string, mixed>
     */
    public static function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_filter(
            $value,
            is_string(...),
            ARRAY_FILTER_USE_KEY,
        );
    }
}

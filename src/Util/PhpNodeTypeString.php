<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

use PhpParser\Node;

final class PhpNodeTypeString
{
    public static function fromNode(Node\Name|Node\Identifier|Node\ComplexType|null $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof Node\NullableType) {
            return '?' . self::fromNode($type->type);
        }

        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(self::fromNode(...), $type->types));
        }

        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(self::fromNode(...), $type->types));
        }

        return $type->toString();
    }
}

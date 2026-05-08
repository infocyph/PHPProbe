<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class ProjectPath
{
    public static function relative(string $path): string
    {
        $root = realpath(getcwd() ?: '.');
        $realPath = realpath($path);

        if (!is_string($root) || !is_string($realPath)) {
            return str_replace('\\', '/', $path);
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = str_replace('\\', '/', $realPath);

        return str_starts_with($normalizedPath, $normalizedRoot . '/')
            ? substr($normalizedPath, strlen($normalizedRoot) + 1)
            : $normalizedPath;
    }
}

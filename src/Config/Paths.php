<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Config;

final class Paths
{
    public static function bundledConfigFile(string $file): string
    {
        $path = self::packageRoot() . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . ltrim($file, '/\\');

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Missing PHPProbe resource "%s": %s', $file, $path));
        }

        return $path;
    }

    public static function config(string $file = 'phpprobe.json'): string
    {
        $projectFile = self::projectRootPath() . DIRECTORY_SEPARATOR . $file;

        return is_file($projectFile) ? $projectFile : self::bundledConfigFile($file);
    }

    public static function preset(string $name): string
    {
        return self::bundledConfigFile('presets/' . $name . '.json');
    }

    public static function projectRootPath(): string
    {
        return getcwd() ?: '.';
    }

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}

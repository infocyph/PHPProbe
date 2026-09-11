<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class InputFileGroups
{
    /**
     * @param list<string> $files
     * @param list<string> $paths
     * @return array<string, list<string>>
     */
    public static function group(array $files, array $paths): array
    {
        $paths = $paths === [] ? ['.'] : $paths;
        $groups = [];
        /** @var list<array{label:string,path:string,file:bool}> $roots */
        $roots = [];

        foreach ($paths as $path) {
            $root = self::absolutePath($path);
            $label = self::groupLabel($path, $root);
            $groups[$label] ??= [];
            $roots[] = ['label' => $label, 'path' => $root, 'file' => is_file($root)];
        }

        foreach ($files as $file) {
            $absoluteFile = self::absolutePath($file);
            $assigned = false;

            foreach ($roots as $root) {
                if (($root['file'] && $absoluteFile === $root['path'])
                    || (!$root['file'] && str_starts_with($absoluteFile, rtrim($root['path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
                    $groups[$root['label']][] = $file;
                    $assigned = true;

                    break;
                }
            }

            if (!$assigned) {
                $groups['(other)'][] = $file;
            }
        }

        return $groups;
    }

    /**
     * @param array<string, list<string>> $groups
     */
    public static function nameFor(string $file, array $groups): string
    {
        $relative = ProjectPath::relative($file);

        foreach ($groups as $name => $files) {
            if (array_any($files, static fn(string $candidate): bool => ProjectPath::relative($candidate) === $relative)) {
                return $name;
            }
        }

        return '(other)';
    }

    /**
     * @param array<string, list<string>> $groups
     * @return list<string>
     */
    public static function roundRobin(array $groups): array
    {
        $queue = [];
        $offset = 0;

        do {
            $added = false;

            foreach ($groups as $files) {
                if (isset($files[$offset])) {
                    $queue[] = $files[$offset];
                    $added = true;
                }
            }

            $offset++;
        } while ($added);

        return $queue;
    }

    private static function absolutePath(string $path): string
    {
        $absolute = preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
        $real = realpath($absolute);

        return is_string($real) ? $real : rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolute), DIRECTORY_SEPARATOR);
    }

    private static function groupLabel(string $path, string $absolute): string
    {
        if ($path === '.' || $path === '') {
            return '.';
        }

        return ProjectPath::relative($absolute);
    }
}

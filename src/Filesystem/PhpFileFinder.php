<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Filesystem;

use Infocyph\PHPProbe\Process\ProcessResult;
use Infocyph\PHPProbe\Process\ProcRunner;

final class PhpFileFinder
{
    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     * @param array{changedOnly?:bool,changedBase?:string} $options
     * @return list<string>
     */
    public function find(array $paths, array $excludes = [], array $options = []): array
    {
        $paths = $paths === [] ? ['.'] : $paths;
        $this->assertPathsExist($paths);
        $normalizedExcludes = $this->normalizedExcludes($excludes);
        $files = $this->gitPhpFiles($paths) ?? $this->recursivePhpFiles($paths, $normalizedExcludes);
        $files = $this->withoutExcludedPaths($files, $normalizedExcludes);

        if (($options['changedOnly'] ?? false) === true) {
            $files = $this->changedFilesSubset($files, $paths, (string) ($options['changedBase'] ?? ''));
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    private function absolutePath(string $path): string
    {
        if (!$this->isAbsolute($path)) {
            $path = (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
        }

        $real = realpath($path);

        return is_string($real) ? $real : $this->normalizePath($path);
    }

    /**
     * @param list<string> $paths
     */
    private function assertPathsExist(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path === '' || (!is_file($this->absolutePath($path)) && !is_dir($this->absolutePath($path)))) {
                throw new \InvalidArgumentException(sprintf('Scan path does not exist: %s', $path === '' ? '(empty)' : $path));
            }
        }
    }

    /**
     * @param list<string> $files
     * @param list<string> $paths
     * @return list<string>
     */
    private function changedFilesSubset(array $files, array $paths, string $base): array
    {
        $changed = $this->gitChangedPhpFiles($paths, $base);

        if ($changed === null) {
            return $files;
        }

        $changedLookup = array_fill_keys($changed, true);

        return array_values(array_filter($files, static fn(string $file): bool => isset($changedLookup[$file])));
    }

    /**
     * @param list<string> $paths
     * @return list<string>|null
     */
    private function gitChangedPhpFiles(array $paths, string $base): ?array
    {
        $baseRef = trim($base);
        $commands = [];
        $commands[] = [
            'git',
            'diff',
            '--name-only',
            '-z',
            '--diff-filter=ACMRTUXB',
            $baseRef !== '' ? $baseRef . '...HEAD' : 'HEAD',
            '--',
            ...$paths,
        ];

        if ($baseRef !== '') {
            $commands[] = ['git', 'diff', '--name-only', '-z', '--diff-filter=ACMRTUXB', 'HEAD', '--', ...$paths];
        }

        $commands[] = ['git', 'ls-files', '-z', '--others', '--exclude-standard', '--', ...$paths];
        $files = [];

        foreach ($commands as $command) {
            $result = new ProcRunner()->run($command);

            if (!$result instanceof ProcessResult || !$result->successful()) {
                return null;
            }

            foreach ($this->phpPathsFromNullList($result->stdout) as $file) {
                $files[$file] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * @param list<string> $paths
     * @return list<string>|null
     */
    private function gitPhpFiles(array $paths): ?array
    {
        $result = new ProcRunner()->run([
            'git',
            'ls-files',
            '-z',
            '--cached',
            '--others',
            '--exclude-standard',
            '--',
            ...$paths,
        ]);

        if (!$result instanceof ProcessResult || !$result->successful()) {
            return null;
        }

        return $this->phpPathsFromNullList($result->stdout);
    }

    private function isAbsolute(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    /**
     * @param list<string> $excludes
     */
    private function isExcluded(string $path, array $excludes): bool
    {
        $normalized = $this->normalizePath($path);

        return array_any($excludes, fn($exclude) => $normalized === $exclude || str_starts_with($normalized, $exclude . DIRECTORY_SEPARATOR));
    }

    /**
     * @param list<string> $excludes
     * @return list<string>
     */
    private function normalizedExcludes(array $excludes): array
    {
        $normalized = [];

        foreach ($excludes as $exclude) {
            if ($exclude !== '') {
                $normalized[] = $this->absolutePath($exclude);
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return $normalized === DIRECTORY_SEPARATOR ? $normalized : rtrim($normalized, DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<string>
     */
    private function phpPathsFromNullList(string $output): array
    {
        $files = [];

        foreach (explode("\0", $output) as $path) {
            if ($path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $absolute = $this->absolutePath($path);

            if (is_file($absolute)) {
                $files[] = $absolute;
            }
        }

        return $files;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     * @return list<string>
     */
    private function recursivePhpFiles(array $paths, array $excludes): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = $this->absolutePath($path);

            if (is_file($absolute)) {
                if (strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'php' && !$this->isExcluded($absolute, $excludes)) {
                    $files[] = $absolute;
                }

                continue;
            }

            $filter = function (\SplFileInfo $file) use ($excludes): bool {
                if ($file->isLink() && $file->isDir()) {
                    return false;
                }

                return !$this->isExcluded($file->getPathname(), $excludes);
            };
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                    $filter,
                ),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * @param list<string> $files
     * @param list<string> $excludes
     * @return list<string>
     */
    private function withoutExcludedPaths(array $files, array $excludes): array
    {
        if ($excludes === []) {
            return $files;
        }

        return array_values(array_filter($files, fn(string $file): bool => !$this->isExcluded($file, $excludes)));
    }
}

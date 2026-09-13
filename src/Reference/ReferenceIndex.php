<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Reference;

/**
 * @phpstan-type SymbolDefinition array{fqcn:string,kind:string,file:string,line:int}
 * @phpstan-type Psr4Mapping array{prefix:string,directories:list<string>,project:bool}
 * @phpstan-type ExtensionRequirement array{package:string,extension:string,section:string,line:int}
 */
final class ReferenceIndex
{
    /** @var array<string, string> */
    private array $classMap = [];

    /** @var array<string, SymbolDefinition> */
    private array $definitions = [];

    /** @var array<string, ExtensionRequirement> */
    private array $extensionRequirements = [];

    /** @var list<Psr4Mapping> */
    private array $psr4 = [];

    public function __construct(private readonly string $composerFile)
    {
        $this->loadComposerMetadata();
        $this->loadInstalledMetadata();
    }

    public function add(string $fqcn, string $kind, string $file, int $line): void
    {
        $normalized = $this->normalize($fqcn);

        if ($normalized === '') {
            return;
        }

        $this->definitions[strtolower($normalized)] = [
            'fqcn' => $normalized,
            'kind' => $kind,
            'file' => $file,
            'line' => max(1, $line),
        ];
    }

    /** @return list<array{fqcn:string,confidence:float}> */
    public function candidates(string $unknown, int $limit = 3): array
    {
        $unknown = $this->normalize($unknown);
        $unknownLower = strtolower($unknown);
        $unknownShort = strtolower($this->shortName($unknown));
        $unknownNamespace = strtolower($this->namespaceName($unknown));
        $scores = [];

        foreach ($this->candidateNames() as $candidate) {
            $candidateLower = strtolower($candidate);
            $candidateShort = strtolower($this->shortName($candidate));
            $shortSimilarity = $this->similarity($unknownShort, $candidateShort);
            $fullSimilarity = $this->similarity($unknownLower, $candidateLower);
            $sameNamespace = $unknownNamespace !== '' && $unknownNamespace === strtolower($this->namespaceName($candidate));
            $score = min(1.0, max($shortSimilarity, $fullSimilarity) + ($sameNamespace ? 0.08 : 0.0));

            if ($score < 0.55) {
                continue;
            }

            $scores[$candidate] = max($scores[$candidate] ?? 0.0, $score);
        }

        arsort($scores, SORT_NUMERIC);
        $result = [];

        foreach (array_slice($scores, 0, max(1, $limit), true) as $fqcn => $score) {
            $result[] = ['fqcn' => $fqcn, 'confidence' => round($score, 4)];
        }

        return $result;
    }

    public function composerPath(): string
    {
        return $this->absolutePath($this->composerFile);
    }

    /** @return list<SymbolDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    public function expectedFqcn(string $file): ?string
    {
        $file = $this->absolutePath($file);
        $matches = [];

        foreach ($this->psr4 as $mapping) {
            if (!$mapping['project']) {
                continue;
            }

            foreach ($mapping['directories'] as $directory) {
                $directory = rtrim($this->absolutePath($directory), DIRECTORY_SEPARATOR);

                if (!str_starts_with($file, $directory . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $relative = substr($file, strlen($directory) + 1);

                if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'php') {
                    continue;
                }

                $class = substr($relative, 0, -4);
                $matches[strlen($directory)] = trim($mapping['prefix'] . str_replace(['/', '\\'], '\\', $class), '\\');
            }
        }

        if ($matches === []) {
            return null;
        }

        krsort($matches, SORT_NUMERIC);

        return reset($matches) ?: null;
    }

    /** @return list<ExtensionRequirement> */
    public function extensionRequirements(): array
    {
        return array_values($this->extensionRequirements);
    }

    public function isKnown(string $fqcn): bool
    {
        $fqcn = $this->normalize($fqcn);

        if ($fqcn === '' || in_array(strtolower($fqcn), ['self', 'static', 'parent'], true)) {
            return true;
        }

        $key = strtolower($fqcn);

        if (isset($this->definitions[$key]) || isset($this->classMap[$key])) {
            return true;
        }

        if (
            class_exists($fqcn, false)
            || interface_exists($fqcn, false)
            || trait_exists($fqcn, false)
            || (function_exists('enum_exists') && enum_exists($fqcn, false))
        ) {
            return true;
        }

        foreach ($this->psr4 as $mapping) {
            if (!str_starts_with($fqcn, $mapping['prefix'])) {
                continue;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($fqcn, strlen($mapping['prefix']))) . '.php';

            foreach ($mapping['directories'] as $directory) {
                if (is_file(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<ExtensionRequirement> */
    public function missingExtensionRequirements(): array
    {
        $loaded = [];

        foreach (get_loaded_extensions() as $extension) {
            $loaded[$this->normalizeExtension($extension)] = true;
        }

        return array_values(array_filter(
            $this->extensionRequirements(),
            fn(array $requirement): bool => !isset($loaded[$this->normalizeExtension($requirement['extension'])]),
        ));
    }

    private function absolutePath(string $path): string
    {
        if (!$this->isAbsolute($path)) {
            $path = (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
        }

        $real = realpath($path);

        return is_string($real) ? $real : str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /** @return list<string> */
    private function candidateNames(): array
    {
        $names = array_column($this->definitions, 'fqcn');

        foreach ($this->classMap as $fqcn) {
            $names[] = $fqcn;
        }

        return array_values(array_unique($names));
    }

    private function composerRoot(): string
    {
        $directory = dirname($this->absolutePath($this->composerFile));

        return is_dir($directory) ? $directory : (getcwd() ?: '.');
    }

    /** @param array<mixed, mixed> $requirements */
    private function importExtensionRequirements(array $requirements, string $section, string $contents): void
    {
        foreach (array_keys($requirements) as $package) {
            if (!is_string($package) || !str_starts_with(strtolower($package), 'ext-')) {
                continue;
            }

            $package = strtolower($package);
            $extension = substr($package, 4);

            if ($extension === '') {
                continue;
            }

            $this->extensionRequirements[$package] = [
                'package' => $package,
                'extension' => $extension,
                'section' => $section,
                'line' => $this->requirementLine($contents, $package),
            ];
        }

        ksort($this->extensionRequirements, SORT_STRING);
    }

    /** @param array<mixed, mixed> $autoload */
    private function importPsr4(array $autoload, string $root, bool $project): void
    {
        $mappings = $autoload['psr-4'] ?? [];

        if (!is_array($mappings)) {
            return;
        }

        foreach ($mappings as $prefix => $directories) {
            if (!is_string($prefix) || (!is_string($directories) && !is_array($directories))) {
                continue;
            }

            $items = is_string($directories) ? [$directories] : array_values(array_filter($directories, is_string(...)));
            $resolved = [];

            foreach ($items as $directory) {
                $resolved[] = $this->isAbsolute($directory) ? $directory : $root . DIRECTORY_SEPARATOR . $directory;
            }

            if ($resolved !== []) {
                $this->psr4[] = ['prefix' => ltrim($prefix, '\\'), 'directories' => $resolved, 'project' => $project];
            }
        }

        usort($this->psr4, static fn(array $left, array $right): int => strlen($right['prefix']) <=> strlen($left['prefix']));
    }

    private function isAbsolute(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    private function loadComposerMetadata(): void
    {
        $file = $this->absolutePath($this->composerFile);

        if (!is_file($file)) {
            return;
        }

        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            return;
        }

        try {
            $composer = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('Invalid Composer JSON at %s: %s', $this->composerFile, $exception->getMessage()), previous: $exception);
        }

        if (!is_array($composer)) {
            return;
        }

        $root = dirname($file);

        foreach (['require', 'require-dev'] as $section) {
            $requirements = $composer[$section] ?? [];

            if (is_array($requirements)) {
                $this->importExtensionRequirements($requirements, $section, $contents);
            }
        }

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? [];

            if (is_array($autoload)) {
                $this->importPsr4($autoload, $root, true);
            }
        }
    }

    private function loadInstalledMetadata(): void
    {
        $composerDirectory = $this->composerRoot() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer';
        $classMapFile = $composerDirectory . DIRECTORY_SEPARATOR . 'autoload_classmap.php';
        $psr4File = $composerDirectory . DIRECTORY_SEPARATOR . 'autoload_psr4.php';

        if (is_file($classMapFile)) {
            $map = require $classMapFile;

            if (is_array($map)) {
                foreach ($map as $fqcn => $file) {
                    if (is_string($fqcn) && is_string($file)) {
                        $this->classMap[strtolower($this->normalize($fqcn))] = $this->normalize($fqcn);
                    }
                }
            }
        }

        if (is_file($psr4File)) {
            $mappings = require $psr4File;

            if (is_array($mappings)) {
                $this->importPsr4(['psr-4' => $mappings], $this->composerRoot(), false);
            }
        }
    }

    private function namespaceName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? '' : substr($fqcn, 0, $position);
    }

    private function normalize(string $fqcn): string
    {
        return trim($fqcn, " \t\n\r\0\x0B\\");
    }

    private function normalizeExtension(string $extension): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', '-', strtolower($extension));

        return trim(is_string($normalized) ? $normalized : '', '-');
    }

    private function requirementLine(string $contents, string $package): int
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (stripos($line, '"' . $package . '"') !== false) {
                return $index + 1;
            }
        }

        return 1;
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    private function similarity(string $left, string $right): float
    {
        $length = max(strlen($left), strlen($right));

        return $length === 0 ? 1.0 : max(0.0, 1.0 - (levenshtein($left, $right) / $length));
    }
}

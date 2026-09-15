<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Graph;

use Infocyph\PHPProbe\Util\ProjectPath;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Deterministically extracts PHP declarations and relationships without an LLM.
 *
 * @phpstan-import-type GraphNode from CodeGraph
 * @phpstan-import-type GraphEdge from CodeGraph
 * @phpstan-type CodeGraphResult array{schema:string,schema_version:int,root:string,files_scanned:int,nodes:list<GraphNode>,edges:list<GraphEdge>}
 */
final readonly class CodeGraphExtractor
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForHostVersion();
    }

    /**
     * @param list<string> $files
     * @return CodeGraphResult
     */
    public function extract(array $files, ?string $root = null): array
    {
        $root = $this->root($root);
        $files = $this->files($files);
        $graph = new CodeGraph();

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if (!is_string($contents)) {
                throw new \RuntimeException(sprintf('Could not read PHP file: %s', $file));
            }

            $relative = $this->relative($file, $root);
            $fileId = 'file:' . $relative;
            $graph->addNode(
                $fileId,
                'file',
                $relative,
                $relative,
                1,
                max(1, substr_count($contents, "\n") + 1),
                true,
                ['content_hash' => hash('sha256', $contents)],
            );

            try {
                $nodes = $this->parser->parse($contents) ?? [];
                new NodeTraverser(
                    new NameResolver(options: ['preserveOriginalNames' => true]),
                    new CodeGraphVisitor($graph, $fileId, $relative),
                )->traverse($nodes);
            } catch (\Throwable $exception) {
                throw new \RuntimeException(
                    sprintf('Could not extract code graph from %s: %s', ProjectPath::relative($file), $exception->getMessage()),
                    previous: $exception,
                );
            }
        }

        return $graph->export('.', $files);
    }

    /** @param list<string> $files
     * @return list<string>
     */
    private function files(array $files): array
    {
        $normalized = [];

        foreach ($files as $file) {
            $absolute = realpath($file);

            if (!is_string($absolute) || !is_file($absolute)) {
                throw new \InvalidArgumentException(sprintf('PHP graph input is not a readable file: %s', $file));
            }

            if (strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $normalized[$absolute] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    private function portablePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function relative(string $file, string $root): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!str_starts_with($file, $prefix)) {
            throw new \InvalidArgumentException(sprintf('PHP graph input must be inside root %s: %s', $root, $file));
        }

        return $this->portablePath(substr($file, strlen($prefix)));
    }

    private function root(?string $root): string
    {
        $root = $root === null || trim($root) === '' ? (getcwd() ?: '.') : $root;
        $absolute = realpath($root);

        if (!is_string($absolute) || !is_dir($absolute)) {
            throw new \InvalidArgumentException(sprintf('PHP graph root is not a readable directory: %s', $root));
        }

        return $absolute;
    }
}

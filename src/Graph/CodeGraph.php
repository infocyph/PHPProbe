<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Graph;

/**
 * Mutable accumulator used only while extracting a deterministic code graph.
 *
 * @internal
 *
 * @phpstan-type GraphNode array{id:string,type:string,name:string,file:string,line:int,end_line:int,defined:bool,attributes:array<string, bool|int|string>}
 * @phpstan-type GraphEdge array{source:string,target:string,relation:string,file:string,line:int,certainty:string,resolution:string}
 * @phpstan-type FunctionCall array{source:string,candidate:string,fallback:string,file:string,line:int}
 */
final class CodeGraph
{
    /** @var array<string, GraphEdge> */
    private array $edges = [];

    /** @var list<FunctionCall> */
    private array $functionCalls = [];

    /** @var array<string, GraphNode> */
    private array $nodes = [];

    public function addEdge(
        string $source,
        string $target,
        string $relation,
        string $file,
        int $line,
        string $resolution = 'exact',
    ): void {
        $edge = [
            'source' => $source,
            'target' => $target,
            'relation' => $relation,
            'file' => $file,
            'line' => max(1, $line),
            'certainty' => 'extracted',
            'resolution' => $resolution,
        ];
        $key = implode("\0", [$source, $target, $relation, $file, (string) $line, $resolution]);
        $this->edges[$key] = $edge;
    }

    public function addFunctionCall(
        string $source,
        string $candidate,
        string $fallback,
        string $file,
        int $line,
    ): void {
        $this->functionCalls[] = [
            'source' => $source,
            'candidate' => $candidate,
            'fallback' => $fallback,
            'file' => $file,
            'line' => $line,
        ];
    }

    /**
     * @param array<string, bool|int|string> $attributes
     */
    public function addNode(
        string $id,
        string $type,
        string $name,
        string $file = '',
        int $line = 0,
        int $endLine = 0,
        bool $defined = false,
        array $attributes = [],
    ): void {
        $candidate = [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'file' => $file,
            'line' => max(0, $line),
            'end_line' => max(0, $endLine),
            'defined' => $defined,
            'attributes' => $attributes,
        ];
        $current = $this->nodes[$id] ?? null;

        if ($current === null || (!$current['defined'] && $defined)) {
            $this->nodes[$id] = $candidate;
        }
    }

    /**
     * @param list<string> $files
     * @return array{schema:string,schema_version:int,root:string,files_scanned:int,nodes:list<GraphNode>,edges:list<GraphEdge>}
     */
    public function export(string $root, array $files): array
    {
        $this->resolveFunctionCalls();
        ksort($this->nodes, SORT_STRING);
        ksort($this->edges, SORT_STRING);

        return [
            'schema' => 'phpprobe.code-graph',
            'schema_version' => 1,
            'root' => $root,
            'files_scanned' => count($files),
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
        ];
    }

    private function resolveFunctionCalls(): void
    {
        foreach ($this->functionCalls as $call) {
            $candidateId = 'function:' . $call['candidate'];
            $candidate = $this->nodes[$candidateId] ?? null;
            $target = $call['candidate'];
            $resolution = 'exact';

            if (($candidate['defined'] ?? false) !== true && !function_exists($target)) {
                if ($call['fallback'] !== '' && function_exists($call['fallback'])) {
                    $target = $call['fallback'];
                } elseif ($call['fallback'] !== '') {
                    $resolution = 'namespace_fallback';
                }
            }

            $targetId = 'function:' . $target;
            $this->addNode($targetId, 'function', $target);
            $this->addEdge($call['source'], $targetId, 'calls', $call['file'], $call['line'], $resolution);
        }

        $this->functionCalls = [];
    }
}

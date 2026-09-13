<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Reference;

use Infocyph\PHPProbe\Util\ProjectPath;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * @phpstan-type ParsedFile array{file:string,nodes:list<Node>}
 * @phpstan-type ReferenceResult array{files_checked:int,symbols_indexed:int,references_checked:int,extensions_checked:int,extensions_missing:int,findings:list<ReferenceFinding>}
 */
final readonly class ReferenceAnalyzer
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForHostVersion();
    }

    /**
     * @param list<string> $analysisFiles
     * @param list<string> $indexFiles
     * @return ReferenceResult
     */
    public function analyze(array $analysisFiles, array $indexFiles, string $composerFile): array
    {
        $allFiles = array_values(array_unique([...$indexFiles, ...$analysisFiles]));
        sort($allFiles, SORT_STRING);
        $parsed = $this->parseFiles($allFiles);
        $index = new ReferenceIndex($composerFile);

        foreach ($parsed as $file) {
            foreach ($this->declarations($file['nodes']) as $definition) {
                $index->add($definition['fqcn'], $definition['kind'], $file['file'], $definition['line']);
            }
        }

        $analysisLookup = array_fill_keys($analysisFiles, true);
        $extensionRequirements = $index->extensionRequirements();
        $missingExtensions = $index->missingExtensionRequirements();
        $findings = $this->missingExtensionFindings($index, $missingExtensions);
        $referencesChecked = 0;

        foreach ($parsed as $file) {
            if (!isset($analysisLookup[$file['file']])) {
                continue;
            }

            $findings = [...$findings, ...$this->declarationPathFindings($file, $index)];

            foreach ($this->references($file['nodes']) as $reference) {
                $referencesChecked++;

                if ($index->isKnown($reference['fqcn'])) {
                    continue;
                }

                $findings[] = $this->unknownFinding($file['file'], $reference, $index);
            }
        }

        $findings = $this->uniqueFindings($findings);
        usort($findings, static fn(ReferenceFinding $left, ReferenceFinding $right): int => [
            $left->file,
            $left->line,
            $left->type,
            $left->symbol,
        ] <=> [
            $right->file,
            $right->line,
            $right->type,
            $right->symbol,
        ]);

        return [
            'files_checked' => count($analysisFiles),
            'symbols_indexed' => count($index->definitions()),
            'references_checked' => $referencesChecked,
            'extensions_checked' => count($extensionRequirements),
            'extensions_missing' => count($missingExtensions),
            'findings' => $findings,
        ];
    }

    /** @param list<array{fqcn:string,line:int,context:string}> $references */
    private function classReferences(array &$references, Node\Stmt\Class_ $node): void
    {
        $this->name($references, $node->extends, 'class inheritance');
        $this->names($references, $node->implements, 'class implementation');
    }

    /**
     * @param ParsedFile $file
     * @return list<ReferenceFinding>
     */
    private function declarationPathFindings(array $file, ReferenceIndex $index): array
    {
        $expected = $index->expectedFqcn($file['file']);

        if ($expected === null) {
            return [];
        }

        $findings = [];

        foreach ($this->declarations($file['nodes']) as $definition) {
            if ($definition['fqcn'] === $expected) {
                continue;
            }

            $findings[] = new ReferenceFinding(
                file: ProjectPath::relative($file['file']),
                line: $definition['line'],
                type: 'psr4_fqcn_mismatch',
                symbol: $definition['fqcn'],
                message: sprintf(
                    '%s %s does not match the Composer PSR-4 location; expected %s.',
                    ucfirst($definition['kind']),
                    $definition['fqcn'],
                    $expected,
                ),
                confidence: 'certain',
                suggestion: sprintf('Change the declaration to %s or move the file to the location for %s.', $expected, $definition['fqcn']),
                candidates: [['fqcn' => $expected, 'confidence' => 1.0]],
            );
        }

        return $findings;
    }

    /**
     * @param list<Node> $nodes
     * @return list<array{fqcn:string,kind:string,line:int}>
     */
    private function declarations(array $nodes): array
    {
        $definitions = [];

        foreach (new NodeFinder()->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
            if ($node->name === null || $node->namespacedName === null) {
                continue;
            }

            $kind = match (true) {
                $node instanceof Node\Stmt\Interface_ => 'interface',
                $node instanceof Node\Stmt\Trait_ => 'trait',
                $node instanceof Node\Stmt\Enum_ => 'enum',
                default => 'class',
            };
            $definitions[] = [
                'fqcn' => $node->namespacedName->toString(),
                'kind' => $kind,
                'line' => $node->getStartLine(),
            ];
        }

        return $definitions;
    }

    /**
     * @param list<array{package:string,extension:string,section:string,line:int}> $requirements
     * @return list<ReferenceFinding>
     */
    private function missingExtensionFindings(ReferenceIndex $index, array $requirements): array
    {
        return array_map(static fn(array $requirement): ReferenceFinding => new ReferenceFinding(
            file: ProjectPath::relative($index->composerPath()),
            line: $requirement['line'],
            type: 'missing_extension',
            symbol: $requirement['package'],
            message: sprintf(
                'Composer %s requires %s, but PHP extension "%s" is not loaded.',
                $requirement['section'],
                $requirement['package'],
                $requirement['extension'],
            ),
            confidence: 'certain',
            suggestion: sprintf(
                'Install or enable %s for the PHP runtime running PHPProbe, then verify it with "php -m".',
                $requirement['package'],
            ),
        ), $requirements);
    }

    /**
     * @param list<array{fqcn:string,line:int,context:string}> $references
     */
    private function name(array &$references, mixed $name, string $context): void
    {
        if (!$name instanceof Node\Name) {
            return;
        }

        $references[] = ['fqcn' => $name->toString(), 'line' => $name->getStartLine(), 'context' => $context];
    }

    /**
     * @param list<array{fqcn:string,line:int,context:string}> $references
     * @param array<array-key, Node\Name> $names
     */
    private function names(array &$references, array $names, string $context): void
    {
        foreach ($names as $name) {
            $this->name($references, $name, $context);
        }
    }

    /**
     * @param list<string> $files
     * @return list<ParsedFile>
     */
    private function parseFiles(array $files): array
    {
        $parsed = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if (!is_string($contents)) {
                throw new \RuntimeException(sprintf('Could not read PHP file: %s', $file));
            }

            try {
                $nodes = $this->parser->parse($contents);
                $nodes = $nodes === null ? [] : new NodeTraverser(new NameResolver())->traverse($nodes);
            } catch (\Throwable $exception) {
                throw new \RuntimeException(sprintf('Could not analyze references in %s: %s', ProjectPath::relative($file), $exception->getMessage()), previous: $exception);
            }

            $parsed[] = ['file' => $file, 'nodes' => array_values($nodes)];
        }

        return $parsed;
    }

    /**
     * @param list<Node> $nodes
     * @return list<array{fqcn:string,line:int,context:string}>
     */
    private function references(array $nodes): array
    {
        $references = [];

        foreach (new NodeFinder()->findInstanceOf($nodes, Node::class) as $node) {
            match (true) {
                $node instanceof Node\Stmt\Class_ => $this->classReferences($references, $node),
                $node instanceof Node\Stmt\Interface_ => $this->names($references, $node->extends, 'interface inheritance'),
                $node instanceof Node\Stmt\Enum_ => $this->names($references, $node->implements, 'enum implementation'),
                $node instanceof Node\Stmt\TraitUse => $this->names($references, $node->traits, 'trait use'),
                $node instanceof Node\Expr\StaticCall,
                $node instanceof Node\Expr\StaticPropertyFetch,
                $node instanceof Node\Expr\ClassConstFetch,
                $node instanceof Node\Expr\New_,
                $node instanceof Node\Expr\Instanceof_ => $this->name($references, $node->class, 'class reference'),
                $node instanceof Node\Stmt\Catch_ => $this->names($references, $node->types, 'catch type'),
                $node instanceof Node\Param => $this->type($references, $node->type, 'parameter type'),
                $node instanceof Node\Stmt\Property => $this->type($references, $node->type, 'property type'),
                $node instanceof Node\Stmt\ClassConst => $this->type($references, $node->type, 'class constant type'),
                $node instanceof Node\FunctionLike => $this->type($references, $node->getReturnType(), 'return type'),
                $node instanceof Node\Attribute => $this->name($references, $node->name, 'attribute'),
                default => null,
            };
        }

        return $references;
    }

    /**
     * @param list<array{fqcn:string,line:int,context:string}> $references
     */
    private function type(array &$references, Node\ComplexType|Node\Identifier|Node\Name|null $type, string $context): void
    {
        if ($type instanceof Node\Name) {
            $this->name($references, $type, $context);

            return;
        }

        if ($type instanceof Node\NullableType) {
            $this->type($references, $type->type, $context);

            return;
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $innerType) {
                $this->type($references, $innerType, $context);
            }
        }
    }

    /**
     * @param list<ReferenceFinding> $findings
     * @return list<ReferenceFinding>
     */
    private function uniqueFindings(array $findings): array
    {
        $unique = [];

        foreach ($findings as $finding) {
            $key = implode("\0", [$finding->file, (string) $finding->line, $finding->type, strtolower($finding->symbol)]);
            $unique[$key] = $finding;
        }

        return array_values($unique);
    }

    /**
     * @param array{fqcn:string,line:int,context:string} $reference
     */
    private function unknownFinding(string $file, array $reference, ReferenceIndex $index): ReferenceFinding
    {
        $candidates = $index->candidates($reference['fqcn']);
        $best = $candidates[0] ?? null;
        $next = $candidates[1] ?? null;
        $certain = $best !== null
            && $best['confidence'] >= 0.90
            && ($next === null || ($best['confidence'] - $next['confidence']) >= 0.10);
        $confidence = $candidates === [] ? 'dead' : ($certain ? 'certain' : 'possible');
        $suggestion = match (true) {
            $certain => sprintf('Replace %s with %s.', $reference['fqcn'], $best['fqcn']),
            $candidates !== [] => 'Review possible replacements: ' . implode(', ', array_column($candidates, 'fqcn')) . '.',
            default => 'No matching class-like symbol was found; this may be a dead reference.',
        };

        return new ReferenceFinding(
            file: ProjectPath::relative($file),
            line: $reference['line'],
            type: 'unknown_fqcn',
            symbol: $reference['fqcn'],
            message: sprintf('Unknown %s %s.', $reference['context'], $reference['fqcn']),
            confidence: $confidence,
            suggestion: $suggestion,
            candidates: $candidates,
        );
    }
}

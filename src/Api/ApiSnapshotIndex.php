<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Api;

use Infocyph\PHPProbe\Util\PhpNodeTypeString;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final readonly class ApiSnapshotIndex
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    /**
     * @param list<string> $files
     * @param array{includeProtected:bool} $options
     * @return array{version:int,generated_at:string,symbols:list<array<string, mixed>>}
     */
    public function build(array $files, array $options): array
    {
        $symbols = [];

        foreach ($files as $file) {
            foreach ($this->symbolsForFile($file, $options['includeProtected']) as $symbol) {
                $symbols[] = $symbol;
            }
        }

        usort($symbols, static fn(array $left, array $right): int => ($left['id'] <=> $right['id']));

        return [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'symbols' => $symbols,
        ];
    }

    private function classLikeKind(Node\Stmt\ClassLike $node): string
    {
        return match (true) {
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Enum_ => 'enum',
            default => 'class',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function classLikeSymbols(Node\Stmt\ClassLike $node, string $namespace, string $file, bool $includeProtected): array
    {
        if ($node->name === null) {
            return [];
        }

        $kind = $this->classLikeKind($node);
        $name = $this->qualifiedName($namespace, $node->name->toString());
        $members = [];

        foreach ($node->stmts as $statement) {
            $member = $this->member($statement, $includeProtected);

            if ($member !== null) {
                $members[] = $member;
            }
        }

        usort($members, static fn(array $left, array $right): int => ($left['id'] <=> $right['id']));

        $symbol = [
            'id' => $kind . ' ' . $name,
            'kind' => $kind,
            'name' => $name,
            'file' => $this->relativePath($file),
            'line' => $node->getStartLine(),
            'modifiers' => $this->classModifiers($node),
            'extends' => $this->extendsName($node),
            'implements' => $this->implementsNames($node),
            'members' => $members,
        ];

        if ($node instanceof Node\Stmt\Enum_) {
            $symbol['backing_type'] = PhpNodeTypeString::fromNode($node->scalarType);
        }

        $symbol['fingerprint'] = $this->fingerprint($symbol);

        return [$symbol];
    }

    /**
     * @return list<string>
     */
    private function classModifiers(Node\Stmt\ClassLike $node): array
    {
        $modifiers = [];

        if ($node instanceof Node\Stmt\Class_) {
            if ($node->isAbstract()) {
                $modifiers[] = 'abstract';
            }

            if ($node->isFinal()) {
                $modifiers[] = 'final';
            }

            if ($node->isReadonly()) {
                $modifiers[] = 'readonly';
            }
        }

        return $modifiers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function constants(Node\Stmt\Const_ $node, string $namespace, string $file): array
    {
        $symbols = [];

        foreach ($node->consts as $constant) {
            $name = $this->qualifiedName($namespace, $constant->name->toString());
            $symbol = [
                'id' => 'constant ' . $name,
                'kind' => 'constant',
                'name' => $name,
                'file' => $this->relativePath($file),
                'line' => $constant->getStartLine(),
                'value' => $this->expr($constant->value),
            ];
            $symbol['fingerprint'] = $this->fingerprint($symbol);
            $symbols[] = $symbol;
        }

        return $symbols;
    }

    /**
     * @param list<Node\Name> $names
     * @return list<string>
     */
    private function declaredNames(array $names): array
    {
        $values = array_map(static fn(Node\Name $name): string => $name->toString(), $names);
        sort($values);

        return $values;
    }

    private function enumCase(Node\Stmt\EnumCase $node): array
    {
        return [
            'id' => 'case ' . $node->name->toString(),
            'kind' => 'case',
            'name' => $node->name->toString(),
            'value' => $this->expr($node->expr),
        ];
    }

    private function expr(?Node\Expr $expr): string
    {
        return $expr instanceof Node\Expr ? $this->printer->prettyPrintExpr($expr) : '';
    }

    private function extendsName(Node\Stmt\ClassLike $node): string
    {
        if ($node instanceof Node\Stmt\Class_) {
            return $node->extends instanceof Node\Name ? $node->extends->toString() : '';
        }

        if ($node instanceof Node\Stmt\Interface_) {
            return implode(',', $this->declaredNames($node->extends));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function fingerprint(array $symbol): string
    {
        unset($symbol['file'], $symbol['line'], $symbol['fingerprint']);

        return hash('sha256', json_encode($symbol, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function functions(Node\Stmt\Function_ $node, string $namespace, string $file): array
    {
        $name = $this->qualifiedName($namespace, $node->name->toString());
        $symbol = [
            'id' => 'function ' . $name,
            'kind' => 'function',
            'name' => $name,
            'file' => $this->relativePath($file),
            'line' => $node->getStartLine(),
            'by_reference' => $node->byRef,
            'parameters' => $this->parameters($node->getParams()),
            'return_type' => PhpNodeTypeString::fromNode($node->getReturnType()),
        ];
        $symbol['fingerprint'] = $this->fingerprint($symbol);

        return [$symbol];
    }

    /**
     * @return list<string>
     */
    private function implementsNames(Node\Stmt\ClassLike $node): array
    {
        if (!$node instanceof Node\Stmt\Class_) {
            return [];
        }

        return $this->declaredNames($node->implements);
    }

    private function includedMemberVisibility(Node\Stmt\ClassMethod|Node\Stmt\Property|Node\Stmt\ClassConst $node, bool $includeProtected): ?string
    {
        $visibility = $this->visibility($node);

        return $this->includedVisibility($visibility, $includeProtected) ? $visibility : null;
    }

    private function includedVisibility(string $visibility, bool $includeProtected): bool
    {
        return $visibility === 'public' || ($includeProtected && $visibility === 'protected');
    }

    private function member(Node\Stmt $node, bool $includeProtected): ?array
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            return $this->method($node, $includeProtected);
        }

        if ($node instanceof Node\Stmt\Property) {
            return $this->property($node, $includeProtected);
        }

        if ($node instanceof Node\Stmt\ClassConst) {
            return $this->memberConstant($node, $includeProtected);
        }

        if ($node instanceof Node\Stmt\EnumCase) {
            return $this->enumCase($node);
        }

        return null;
    }

    private function memberConstant(Node\Stmt\ClassConst $node, bool $includeProtected): ?array
    {
        $visibility = $this->includedMemberVisibility($node, $includeProtected);

        if ($visibility === null) {
            return null;
        }

        return [
            'id' => $this->memberItemId('constant', $node->consts),
            'kind' => 'constant',
            'visibility' => $visibility,
            'final' => $node->isFinal(),
            'constants' => $this->namedExpressionItems($node->consts),
        ];
    }

    /**
     * @param array<int, object> $items
     */
    private function memberItemId(string $kind, array $items): string
    {
        return $kind . ' ' . implode(',', array_column($this->namedExpressionItems($items), 'name'));
    }

    private function method(Node\Stmt\ClassMethod $node, bool $includeProtected): ?array
    {
        $visibility = $this->includedMemberVisibility($node, $includeProtected);

        if ($visibility === null) {
            return null;
        }

        return [
            'id' => 'method ' . $node->name->toString(),
            'kind' => 'method',
            'name' => $node->name->toString(),
            'visibility' => $visibility,
            'abstract' => $node->isAbstract(),
            'final' => $node->isFinal(),
            'static' => $node->isStatic(),
            'by_reference' => $node->byRef,
            'parameters' => $this->parameters($node->getParams()),
            'return_type' => PhpNodeTypeString::fromNode($node->getReturnType()),
        ];
    }

    /**
     * @param array<int, object> $items
     * @return list<array{name:string,value:string}>
     */
    private function namedExpressionItems(array $items): array
    {
        $values = [];

        foreach ($items as $item) {
            $expr = $item->value ?? $item->default ?? null;
            $values[] = [
                'name' => $item->name->toString(),
                'value' => $expr instanceof Node\Expr ? $this->expr($expr) : '',
            ];
        }

        return $values;
    }

    /**
     * @param list<Node\Param> $parameters
     * @return list<array<string, mixed>>
     */
    private function parameters(array $parameters): array
    {
        $items = [];

        foreach ($parameters as $parameter) {
            $items[] = [
                'name' => $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name) ? $parameter->var->name : '',
                'type' => PhpNodeTypeString::fromNode($parameter->type),
                'by_reference' => $parameter->byRef,
                'variadic' => $parameter->variadic,
                'optional' => $parameter->default instanceof Node\Expr,
                'default' => $this->expr($parameter->default),
            ];
        }

        return $items;
    }

    private function property(Node\Stmt\Property $node, bool $includeProtected): ?array
    {
        $visibility = $this->includedMemberVisibility($node, $includeProtected);

        if ($visibility === null) {
            return null;
        }

        return [
            'id' => $this->memberItemId('property', $node->props),
            'kind' => 'property',
            'visibility' => $visibility,
            'static' => $node->isStatic(),
            'readonly' => $node->isReadonly(),
            'type' => PhpNodeTypeString::fromNode($node->type),
            'properties' => $this->namedExpressionItems($node->props),
        ];
    }

    private function qualifiedName(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    private function relativePath(string $path): string
    {
        $root = getcwd() ?: '';
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if ($normalizedRoot !== '' && str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return $normalizedPath;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function symbolsForFile(string $file, bool $includeProtected): array
    {
        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            return [];
        }

        try {
            $nodes = (new ParserFactory())->createForHostVersion()->parse($contents);
        } catch (\Throwable) {
            return [];
        }

        if ($nodes === null) {
            return [];
        }

        return $this->symbolsInNodes($nodes, '', $file, $includeProtected);
    }

    /**
     * @param list<Node> $nodes
     * @return list<array<string, mixed>>
     */
    private function symbolsInNodes(array $nodes, string $namespace, string $file, bool $includeProtected): array
    {
        $symbols = [];

        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $symbols = [...$symbols, ...$this->symbolsInNodes($node->stmts, $node->name?->toString() ?? '', $file, $includeProtected)];

                continue;
            }

            if ($node instanceof Node\Stmt\ClassLike) {
                $symbols = [...$symbols, ...$this->classLikeSymbols($node, $namespace, $file, $includeProtected)];

                continue;
            }

            if ($node instanceof Node\Stmt\Function_) {
                $symbols = [...$symbols, ...$this->functions($node, $namespace, $file)];

                continue;
            }

            if ($node instanceof Node\Stmt\Const_) {
                $symbols = [...$symbols, ...$this->constants($node, $namespace, $file)];
            }
        }

        return $symbols;
    }

    private function visibility(Node\Stmt\ClassMethod|Node\Stmt\Property|Node\Stmt\ClassConst $node): string
    {
        if ($node->isPrivate()) {
            return 'private';
        }

        if ($node->isProtected()) {
            return 'protected';
        }

        return 'public';
    }
}

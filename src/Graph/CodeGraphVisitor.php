<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Graph;

use Infocyph\PHPProbe\Util\PhpNodeTypeString;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class CodeGraphVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $callables = [];

    /** @var list<string> */
    private array $classes = [];

    private ?string $namespace = null;

    /** @var array<int, string|null> */
    private array $parents = [];

    public function __construct(
        private readonly CodeGraph $graph,
        private readonly string $fileId,
        private readonly string $file,
    ) {}

    public function enterNode(Node $node): null
    {
        match (true) {
            $node instanceof Node\Stmt\Namespace_ => $this->enterNamespace($node),
            $node instanceof Node\Stmt\ClassLike => $this->enterClassLike($node),
            $node instanceof Node\Stmt\Function_ => $this->enterFunction($node),
            $node instanceof Node\Stmt\ClassMethod => $this->enterMethod($node),
            $node instanceof Node\Expr\Closure,
            $node instanceof Node\Expr\ArrowFunction => $this->enterClosure($node),
            $node instanceof Node\Stmt\Property => $this->property($node),
            $node instanceof Node\Stmt\ClassConst => $this->classConstant($node),
            $node instanceof Node\Stmt\Const_ => $this->constant($node),
            $node instanceof Node\Stmt\EnumCase => $this->enumCase($node),
            $node instanceof Node\Stmt\Use_ => $this->imports($node),
            $node instanceof Node\Stmt\GroupUse => $this->groupImports($node),
            $node instanceof Node\Stmt\TraitUse => $this->traitUses($node),
            $node instanceof Node\Param => $this->referenceType($node->type, $node),
            $node instanceof Node\Attribute => $this->attribute($node),
            $node instanceof Node\Stmt\Catch_ => $this->catchTypes($node),
            $node instanceof Node\Expr\FuncCall => $this->functionCall($node),
            $node instanceof Node\Expr\StaticCall => $this->staticCall($node),
            $node instanceof Node\Expr\MethodCall,
            $node instanceof Node\Expr\NullsafeMethodCall => $this->methodCall($node),
            $node instanceof Node\Expr\New_ => $this->instantiation($node),
            $node instanceof Node\Expr\Instanceof_,
            $node instanceof Node\Expr\ClassConstFetch,
            $node instanceof Node\Expr\StaticPropertyFetch => $this->classReference($node->class, $node),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
        ) {
            array_pop($this->callables);
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classes);
            array_pop($this->parents);
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = null;
        }

        return null;
    }

    private function addClassTarget(Node\Name $name): string
    {
        $fqcn = $this->resolvedName($name);
        $id = 'class-like:' . $fqcn;
        $this->graph->addNode($id, 'class', $fqcn);

        return $id;
    }

    /** @param array<string, bool|int|string> $attributes */
    private function addDeclaration(Node $node, string $id, string $type, string $name, array $attributes = []): void
    {
        $documentation = $this->documentation($node);

        if ($documentation !== '') {
            $attributes['documentation'] = $documentation;
        }

        $this->graph->addNode(
            $id,
            $type,
            $name,
            $this->file,
            $node->getStartLine(),
            $node->getEndLine(),
            true,
            $attributes,
        );
        $this->graph->addEdge($this->container(), $id, 'contains', $this->file, $node->getStartLine());
    }

    private function attribute(Node\Attribute $node): void
    {
        $target = $this->addClassTarget($node->name);
        $this->graph->addEdge($this->container(), $target, 'annotated_by', $this->file, $node->getStartLine());
    }

    private function callable(): ?string
    {
        return $this->callables === [] ? null : $this->callables[array_key_last($this->callables)];
    }

    /**
     * @param array<string, bool|int|string> $attributes
     * @return array<string, bool|int|string>
     */
    private function callableAttributes(Node\FunctionLike $node, array $attributes): array
    {
        $parameters = [];

        foreach ($node->getParams() as $parameter) {
            $type = PhpNodeTypeString::fromNode($parameter->type);
            $name = $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                ? '$' . $parameter->var->name
                : '$?';
            $parameters[] = ($type === '' ? '' : $type . ' ')
                . ($parameter->byRef ? '&' : '')
                . ($parameter->variadic ? '...' : '')
                . $name;
        }

        $returnType = PhpNodeTypeString::fromNode($node->getReturnType());
        $attributes['parameter_count'] = count($parameters);
        $attributes['signature'] = '(' . implode(', ', $parameters) . ')' . ($returnType === '' ? '' : ': ' . $returnType);

        if ($returnType !== '') {
            $attributes['return_type'] = $returnType;
        }

        return $attributes;
    }

    private function catchTypes(Node\Stmt\Catch_ $node): void
    {
        foreach ($node->types as $type) {
            $this->classReference($type, $node);
        }
    }

    private function classConstant(Node\Stmt\ClassConst $node): void
    {
        $class = $this->classId();

        if ($class === null) {
            return;
        }

        foreach ($node->consts as $constant) {
            $name = $constant->name->toString();
            $this->addDeclaration($constant, 'constant:' . $this->symbolName($class) . '::' . $name, 'constant', $name);
        }

        $this->referenceType($node->type, $node);
    }

    private function classId(): ?string
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    private function classReference(mixed $name, Node $source): void
    {
        if (!$name instanceof Node\Name) {
            return;
        }

        $target = $this->addClassTarget($name);
        $this->graph->addEdge($this->container(), $target, 'references', $this->file, $source->getStartLine());
    }

    private function constant(Node\Stmt\Const_ $node): void
    {
        foreach ($node->consts as $constant) {
            $name = $constant->namespacedName?->toString() ?? $this->qualified($constant->name->toString());
            $this->addDeclaration($constant, 'constant:' . $name, 'constant', $name);
        }
    }

    private function container(): string
    {
        return $this->callable() ?? $this->classId() ?? $this->namespace ?? $this->fileId;
    }

    private function documentation(Node $node): string
    {
        $comment = $node->getDocComment();

        if ($comment === null) {
            return '';
        }

        $lines = preg_split('/\R/', $comment->getText()) ?: [];
        $description = [];

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '@')) {
                break;
            }

            $description[] = $line;
        }

        return substr(implode(' ', $description), 0, 500);
    }

    private function enterClassLike(Node\Stmt\ClassLike $node): void
    {
        $fqcn = $node->name !== null && $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : sprintf('@anonymous:%s:%d:%d', $this->file, $node->getStartLine(), max(0, $node->getStartFilePos()));
        $type = match (true) {
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Enum_ => 'enum',
            $node->name === null => 'anonymous_class',
            default => 'class',
        };
        $id = 'class-like:' . $fqcn;
        $parent = $node instanceof Node\Stmt\Class_ && $node->extends instanceof Node\Name
            ? $this->resolvedName($node->extends)
            : null;
        $attributes = $node instanceof Node\Stmt\Class_
            ? ['abstract' => $node->isAbstract(), 'final' => $node->isFinal(), 'readonly' => $node->isReadonly()]
            : [];
        $this->addDeclaration($node, $id, $type, $fqcn, $attributes);
        $this->classes[] = $id;
        $this->parents[] = $parent;

        if ($node instanceof Node\Stmt\Class_ && $node->extends instanceof Node\Name) {
            $this->relationshipToClass($id, $node->extends, 'extends', $node);
        }

        $interfaces = match (true) {
            $node instanceof Node\Stmt\Class_, $node instanceof Node\Stmt\Enum_ => $node->implements,
            $node instanceof Node\Stmt\Interface_ => $node->extends,
            default => [],
        };

        foreach ($interfaces as $interface) {
            $this->relationshipToClass($id, $interface, $node instanceof Node\Stmt\Interface_ ? 'extends' : 'implements', $node);
        }
    }

    private function enterClosure(Node\Expr\Closure|Node\Expr\ArrowFunction $node): void
    {
        $kind = $node instanceof Node\Expr\ArrowFunction ? 'arrow_function' : 'closure';
        $name = sprintf('@%s:%s:%d:%d', $kind, $this->file, $node->getStartLine(), max(0, $node->getStartFilePos()));
        $id = $kind . ':' . $name;
        $this->addDeclaration($node, $id, $kind, $name, $this->callableAttributes($node, ['static' => $node->static]));
        $this->callables[] = $id;
        $this->referenceType($node->returnType, $node);
    }

    private function enterFunction(Node\Stmt\Function_ $node): void
    {
        $name = $node->namespacedName?->toString() ?? $this->qualified($node->name->toString());
        $id = 'function:' . $name;
        $this->addDeclaration($node, $id, 'function', $name, $this->callableAttributes($node, [
            'returns_by_reference' => $node->byRef,
        ]));
        $this->callables[] = $id;
        $this->referenceType($node->returnType, $node);
    }

    private function enterMethod(Node\Stmt\ClassMethod $node): void
    {
        $class = $this->classId();

        if ($class === null) {
            return;
        }

        $name = $node->name->toString();
        $id = 'method:' . $this->symbolName($class) . '::' . $name;
        $this->addDeclaration($node, $id, 'method', $name, $this->callableAttributes($node, [
            'abstract' => $node->isAbstract(),
            'final' => $node->isFinal(),
            'static' => $node->isStatic(),
            'visibility' => $this->visibility($node),
            'returns_by_reference' => $node->byRef,
        ]));
        $this->callables[] = $id;
        $this->referenceType($node->returnType, $node);
    }

    private function enterNamespace(Node\Stmt\Namespace_ $node): void
    {
        if ($node->name === null) {
            $this->namespace = null;

            return;
        }

        $name = $node->name->toString();
        $this->namespace = 'namespace:' . $name;
        $this->graph->addNode(
            $this->namespace,
            'namespace',
            $name,
            $this->file,
            $node->getStartLine(),
            $node->getEndLine(),
            true,
        );
        $this->graph->addEdge($this->fileId, $this->namespace, 'contains', $this->file, $node->getStartLine());
    }

    private function enumCase(Node\Stmt\EnumCase $node): void
    {
        $class = $this->classId();

        if ($class === null) {
            return;
        }

        $name = $node->name->toString();
        $this->addDeclaration($node, 'enum_case:' . $this->symbolName($class) . '::' . $name, 'enum_case', $name);
    }

    private function functionCall(Node\Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Node\Name) {
            return;
        }

        $name = $this->resolvedName($node->name);
        $original = $node->name->getAttribute('originalName');
        $fallback = $original instanceof Node\Name
            && !$original->isFullyQualified()
            && !$original->isRelative()
            && count($original->getParts()) === 1
            && $name === $this->qualified($original->toString())
                ? $original->toString()
                : '';
        $this->graph->addFunctionCall($this->container(), $name, $fallback, $this->file, $node->getStartLine());
    }

    private function groupImports(Node\Stmt\GroupUse $node): void
    {
        foreach ($node->uses as $use) {
            $name = new Node\Name($node->prefix->toString() . '\\' . $use->name->toString());
            $type = $use->type !== Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type;
            $this->import($name, $use->getStartLine(), $type);
        }
    }

    private function import(Node\Name $name, int $line, int $type): void
    {
        $qualified = ltrim($name->toString(), '\\');
        $target = match ($type) {
            Node\Stmt\Use_::TYPE_FUNCTION => 'function:' . $qualified,
            Node\Stmt\Use_::TYPE_CONSTANT => 'constant:' . $qualified,
            default => 'class-like:' . $qualified,
        };
        $nodeType = match ($type) {
            Node\Stmt\Use_::TYPE_FUNCTION => 'function',
            Node\Stmt\Use_::TYPE_CONSTANT => 'constant',
            default => 'class',
        };
        $this->graph->addNode($target, $nodeType, $qualified);
        $this->graph->addEdge($this->namespace ?? $this->fileId, $target, 'imports', $this->file, $line);
    }

    private function imports(Node\Stmt\Use_ $node): void
    {
        foreach ($node->uses as $use) {
            $type = $use->type !== Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type;
            $this->import($use->name, $use->getStartLine(), $type);
        }
    }

    private function instantiation(Node\Expr\New_ $node): void
    {
        if (!$node->class instanceof Node\Name) {
            return;
        }

        $target = $this->addClassTarget($node->class);
        $this->graph->addEdge($this->container(), $target, 'instantiates', $this->file, $node->getStartLine());
    }

    private function methodCall(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node): void
    {
        if (!$node->name instanceof Node\Identifier) {
            return;
        }

        $method = $node->name->toString();
        $class = $node->var instanceof Node\Expr\Variable && $node->var->name === 'this' ? $this->classId() : null;
        $target = $class === null
            ? 'method-name:' . strtolower($method)
            : 'method:' . $this->symbolName($class) . '::' . $method;
        $this->graph->addNode($target, $class === null ? 'method_name' : 'method', $method);
        $this->graph->addEdge(
            $this->container(),
            $target,
            'calls',
            $this->file,
            $node->getStartLine(),
            $class === null ? 'dynamic' : 'exact',
        );
    }

    private function property(Node\Stmt\Property $node): void
    {
        $class = $this->classId();

        if ($class === null) {
            return;
        }

        foreach ($node->props as $property) {
            $name = '$' . $property->name->toString();
            $attributes = [
                'readonly' => $node->isReadonly(),
                'static' => $node->isStatic(),
                'visibility' => $this->visibility($node),
            ];
            $type = PhpNodeTypeString::fromNode($node->type);

            if ($type !== '') {
                $attributes['type'] = $type;
            }

            $this->addDeclaration($property, 'property:' . $this->symbolName($class) . '::' . $name, 'property', $name, $attributes);
        }

        $this->referenceType($node->type, $node);
    }

    private function qualified(string $name): string
    {
        return $this->namespace === null ? $name : substr($this->namespace, 10) . '\\' . $name;
    }

    private function referenceType(Node\ComplexType|Node\Identifier|Node\Name|null $type, Node $source): void
    {
        if ($type instanceof Node\Name) {
            $this->classReference($type, $source);

            return;
        }

        if ($type instanceof Node\NullableType) {
            $this->referenceType($type->type, $source);

            return;
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $innerType) {
                $this->referenceType($innerType, $source);
            }
        }
    }

    private function relationshipToClass(string $source, Node\Name $name, string $relation, Node $node): void
    {
        $target = $this->addClassTarget($name);
        $this->graph->addEdge($source, $target, $relation, $this->file, $node->getStartLine());
    }

    private function resolvedName(Node\Name $name): string
    {
        $special = strtolower($name->toString());

        if (($special === 'self' || $special === 'static') && $this->classId() !== null) {
            return $this->symbolName($this->classId());
        }

        if ($special === 'parent' && $this->parents !== []) {
            $parent = $this->parents[array_key_last($this->parents)];

            if ($parent !== null) {
                return $parent;
            }
        }

        $resolved = $name->getAttribute('resolvedName');

        if (!$resolved instanceof Node\Name) {
            $resolved = $name->getAttribute('namespacedName');
        }

        return ltrim($resolved instanceof Node\Name ? $resolved->toString() : $name->toString(), '\\');
    }

    private function staticCall(Node\Expr\StaticCall $node): void
    {
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return;
        }

        $class = $this->resolvedName($node->class);
        $method = $node->name->toString();
        $target = 'method:' . $class . '::' . $method;
        $this->graph->addNode($target, 'method', $method);
        $this->graph->addEdge($this->container(), $target, 'calls', $this->file, $node->getStartLine());
    }

    private function symbolName(string $id): string
    {
        $separator = strpos($id, ':');

        return $separator === false ? $id : substr($id, $separator + 1);
    }

    private function traitUses(Node\Stmt\TraitUse $node): void
    {
        $class = $this->classId();

        if ($class === null) {
            return;
        }

        foreach ($node->traits as $trait) {
            $this->relationshipToClass($class, $trait, 'uses', $node);
        }
    }

    private function visibility(Node\Stmt\ClassMethod|Node\Stmt\Property $node): string
    {
        return match (true) {
            $node->isPrivate() => 'private',
            $node->isProtected() => 'protected',
            default => 'public',
        };
    }
}

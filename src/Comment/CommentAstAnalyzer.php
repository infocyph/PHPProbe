<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Comment;

use Infocyph\PHPProbe\Util\PhpDocParsing;
use Infocyph\PHPProbe\Util\PhpNodeTypeString;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;

/**
 * @phpstan-type Symbol array{id:string,aliases:list<string>,start_line:int,end_line:int}
 * @phpstan-type Finding array{line:int,end_line:int,type:string,message:string,confidence:string,subtype:?string,explanation:?string,suggestion:?string,raw:?string}
 * @phpstan-type Symbols list<Symbol>
 * @phpstan-type Findings list<Finding>
 * @phpstan-type Options array<string, mixed>
 */
final class CommentAstAnalyzer
{
    private ?Lexer $phpDocLexer = null;

    private ?PhpDocParser $phpDocParser = null;

    /**
     * @param Options $options
     * @return array{
     *     symbols: Symbols,
     *     findings: Findings
     * }
     */
    public function analyze(string $file, array $options): array
    {
        $contents = file_get_contents($file);

        if (!is_string($contents) || trim($contents) === '') {
            return ['symbols' => [], 'findings' => []];
        }

        try {
            $nodes = new ParserFactory()->createForHostVersion()->parse($contents);
        } catch (\Throwable) {
            return ['symbols' => [], 'findings' => []];
        }

        if ($nodes === null) {
            return ['symbols' => [], 'findings' => []];
        }

        $symbols = [];
        $findings = [];
        $this->analyzeNodes(array_values($nodes), '', $symbols, $findings, $options);

        return ['symbols' => $symbols, 'findings' => $findings];
    }

    /**
     * @param Symbols $symbols
     * @param list<string> $aliases
     */
    private function addSymbol(array &$symbols, string $id, array $aliases, int $startLine, int $endLine): void
    {
        $normalized = [];

        foreach ($aliases as $alias) {
            $value = trim($alias);
            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
            $normalized[] = strtolower($value);
        }

        $symbols[] = [
            'id' => $id,
            'aliases' => array_values(array_unique($normalized)),
            'start_line' => max(1, $startLine),
            'end_line' => max($startLine, $endLine),
        ];
    }

    /**
     * @param Symbols $symbols
     * @param Findings $findings
     * @param Options $options
     */
    private function analyzeClassLikeNode(
        Node\Stmt\ClassLike $node,
        string $namespace,
        array &$symbols,
        array &$findings,
        array $options,
    ): void {
        if ($node->name === null) {
            return;
        }

        $localClassName = $node->name->toString();
        $fqcn = $this->qualifiedName($namespace, $localClassName);

        $this->addSymbol(
            $symbols,
            $fqcn,
            [
                $fqcn,
                $localClassName,
                'class ' . $fqcn,
                'class ' . $localClassName,
            ],
            $node->getStartLine(),
            $node->getEndLine(),
        );

        foreach ($node->stmts as $statement) {
            match (true) {
                $statement instanceof Node\Stmt\ClassMethod => $this->analyzeClassMethodNode(
                    $statement,
                    $fqcn,
                    $localClassName,
                    $symbols,
                    $findings,
                    $options,
                ),
                $statement instanceof Node\Stmt\Property => $this->analyzeClassMemberNodes(
                    array_values($statement->props),
                    $fqcn,
                    $localClassName,
                    '::$',
                    $statement->getStartLine(),
                    $statement->getEndLine(),
                    $symbols,
                ),
                $statement instanceof Node\Stmt\ClassConst => $this->analyzeClassMemberNodes(
                    array_values($statement->consts),
                    $fqcn,
                    $localClassName,
                    '::',
                    $statement->getStartLine(),
                    $statement->getEndLine(),
                    $symbols,
                ),
                default => null,
            };
        }
    }

    /**
     * @param list<Node\Const_|Node\PropertyItem> $members
     * @param Symbols $symbols
     */
    private function analyzeClassMemberNodes(
        array $members,
        string $fqcn,
        string $localClassName,
        string $separator,
        int $startLine,
        int $endLine,
        array &$symbols,
    ): void {
        foreach ($members as $member) {
            $name = $member->name->toString();
            $qualifiedMember = $fqcn . $separator . $name;

            $this->addSymbol(
                $symbols,
                $qualifiedMember,
                [
                    $qualifiedMember,
                    $localClassName . $separator . $name,
                ],
                $startLine,
                $endLine,
            );
        }
    }

    /**
     * @param Symbols $symbols
     * @param Findings $findings
     * @param Options $options
     */
    private function analyzeClassMethodNode(
        Node\Stmt\ClassMethod $node,
        string $fqcn,
        string $localClassName,
        array &$symbols,
        array &$findings,
        array $options,
    ): void {
        $method = $node->name->toString();
        $qualifiedMethod = $fqcn . '::' . $method;

        $this->addSymbol(
            $symbols,
            $qualifiedMethod,
            [
                $qualifiedMethod,
                $localClassName . '::' . $method,
                $method,
            ],
            $node->getStartLine(),
            $node->getEndLine(),
        );

        $this->analyzeFunctionLike(
            $node,
            $qualifiedMethod,
            $findings,
            $options,
        );
    }

    /** @param Symbols $symbols */
    private function analyzeConstantNode(
        Node\Stmt\Const_ $node,
        string $namespace,
        array &$symbols,
    ): void {
        foreach ($node->consts as $constant) {
            $name = $this->qualifiedName(
                $namespace,
                $constant->name->toString(),
            );

            $this->addSymbol(
                $symbols,
                $name,
                [
                    $name,
                    'constant ' . $name,
                ],
                $constant->getStartLine(),
                $constant->getEndLine(),
            );
        }
    }

    /**
     * @param Findings $findings
     * @param Options $options
     */
    private function analyzeFunctionLike(Node\FunctionLike $node, string $symbol, array &$findings, array $options): void
    {
        $checkSignatures = ($options['docSignatureConsistency'] ?? true) === true;
        $checkTypeHygiene = ($options['docTypeHygiene'] ?? true) === true;

        if (!$checkSignatures && !$checkTypeHygiene) {
            return;
        }

        $doc = $node->getDocComment();

        if ($doc === null) {
            return;
        }

        $docLine = max(1, $doc->getStartLine());
        $docRaw = $doc->getText();

        try {
            $docNode = $this->phpDocParser()->parse(new TokenIterator($this->phpDocLexer()->tokenize($docRaw)));
        } catch (\Throwable $exception) {
            if ($checkTypeHygiene) {
                $findings[] = [
                    'line' => $docLine,
                    'end_line' => $docLine,
                    'type' => 'phpdoc_invalid_tag_value',
                    'message' => sprintf('Invalid PHPDoc syntax on symbol "%s".', $symbol),
                    'confidence' => 'high',
                    'subtype' => 'phpdoc_parse_error',
                    'explanation' => ($options['explain'] ?? false) === true
                        ? $exception->getMessage()
                        : null,
                    'suggestion' => 'Fix malformed PHPDoc tags so they can be parsed consistently.',
                    'raw' => null,
                ];
            }

            return;
        }

        if ($checkTypeHygiene) {
            $this->collectTypeHygieneFindings($docNode, $symbol, $docLine, $findings, $options);
        }

        if ($checkSignatures) {
            $this->collectSignatureFindings($node, $docNode, $symbol, $docLine, $findings, $options);
        }
    }

    /**
     * @param Symbols $symbols
     * @param Findings $findings
     * @param Options $options
     */
    private function analyzeFunctionNode(
        Node\Stmt\Function_ $node,
        string $namespace,
        array &$symbols,
        array &$findings,
        array $options,
    ): void {
        $function = $node->name->toString();
        $fqfn = $this->qualifiedName($namespace, $function);

        $this->addSymbol(
            $symbols,
            $fqfn,
            [
                $fqfn,
                $function,
                'function ' . $fqfn,
                'function ' . $function,
            ],
            $node->getStartLine(),
            $node->getEndLine(),
        );

        $this->analyzeFunctionLike(
            $node,
            $fqfn,
            $findings,
            $options,
        );
    }

    /**
     * @param Symbols $symbols
     * @param Findings $findings
     * @param Options $options
     */
    private function analyzeNamespaceNode(
        Node\Stmt\Namespace_ $node,
        array &$symbols,
        array &$findings,
        array $options,
    ): void {
        $this->analyzeNodes(
            array_values($node->stmts),
            $node->name?->toString() ?? '',
            $symbols,
            $findings,
            $options,
        );
    }

    /**
     * @param list<Node> $nodes
     * @param Symbols $symbols
     * @param Findings $findings
     * @param Options $options
     */
    private function analyzeNodes(
        array $nodes,
        string $namespace,
        array &$symbols,
        array &$findings,
        array $options,
    ): void {
        foreach ($nodes as $node) {
            match (true) {
                $node instanceof Node\Stmt\Namespace_ => $this->analyzeNamespaceNode(
                    $node,
                    $symbols,
                    $findings,
                    $options,
                ),
                $node instanceof Node\Stmt\ClassLike => $this->analyzeClassLikeNode(
                    $node,
                    $namespace,
                    $symbols,
                    $findings,
                    $options,
                ),
                $node instanceof Node\Stmt\Function_ => $this->analyzeFunctionNode(
                    $node,
                    $namespace,
                    $symbols,
                    $findings,
                    $options,
                ),
                $node instanceof Node\Stmt\Const_ => $this->analyzeConstantNode(
                    $node,
                    $namespace,
                    $symbols,
                ),
                default => null,
            };
        }
    }

    private function atomicTypeIsCompatible(string $documented, string $native): bool
    {
        if ($documented === $native || $native === 'mixed') {
            return true;
        }

        $intersection = $this->topLevelTypeParts($documented, '&');

        if (count($intersection) > 1) {
            return in_array($native, $intersection, true);
        }

        return match ($native) {
            'array' => preg_match(
                '/^(?:array|list|non-empty-array|non-empty-list)(?:[<{]|$)/',
                $documented,
            ) === 1 || str_ends_with($documented, '[]'),

            'iterable' => preg_match(
                '/^(?:array|list|iterable|non-empty-array|non-empty-list)(?:[<{]|$)/',
                $documented,
            ) === 1 || str_ends_with($documented, '[]'),

            'callable' => str_starts_with($documented, 'callable('),

            'bool' => $documented === 'false' || $documented === 'true',

            'int' => preg_match(
                '/^(?:int<|negative-int$|non-negative-int$|non-positive-int$|positive-int$)/',
                $documented,
            ) === 1,

            'string' => preg_match(
                '/^(?:callable-string|class-string|literal-string|lowercase-string|non-empty-string|non-falsy-string|numeric-string|trait-string|uppercase-string)(?:<|$)/',
                $documented,
            ) === 1,

            default => ($genericOffset = strpos($documented, '<')) !== false
                && substr($documented, 0, $genericOffset) === $native,
        };
    }

    /**
     * @return array{
     *     0: array<string, string>,
     *     1: array<string, true>
     * }
     */
    private function collectDocumentedParameters(PhpDocNode $docNode): array
    {
        $types = [];
        $names = [];

        foreach ($docNode->getParamTagValues() as $tag) {
            $name = ltrim($tag->parameterName, '$');

            if ($name === '') {
                continue;
            }

            $names[$name] = true;
            $types[$name] = $this->normalizeType((string) $tag->type);
        }

        foreach ($docNode->getTypelessParamTagValues() as $tag) {
            $name = ltrim($tag->parameterName, '$');

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return [$types, $names];
    }

    /**
     * @param array<string, string> $signatureParams
     * @param array<string, string> $docParamTypes
     * @param array<string, true> $docParamNames
     * @param Findings $findings
     */
    private function collectParameterSignatureFindings(
        array $signatureParams,
        array $docParamTypes,
        array $docParamNames,
        string $symbol,
        int $docLine,
        bool $explain,
        array &$findings,
    ): void {
        foreach ($docParamNames as $name => $_present) {
            if (array_key_exists($name, $signatureParams)) {
                continue;
            }

            $findings[] = $this->signatureFinding(
                line: $docLine,
                type: 'phpdoc_unknown_param',
                message: sprintf(
                    'PHPDoc references unknown parameter "$%s" on symbol "%s".',
                    $name,
                    $symbol,
                ),
                confidence: 'high',
                subtype: 'param_not_in_signature',
                explanation: $explain
                    ? 'Parameter exists in PHPDoc but not in function signature.'
                    : null,
                suggestion: 'Rename or remove the extra @param tag.',
            );
        }

        foreach ($signatureParams as $name => $nativeType) {
            $finding = match (true) {
                !isset($docParamNames[$name]) => $this->signatureFinding(
                    line: $docLine,
                    type: 'phpdoc_missing_param',
                    message: sprintf(
                        'PHPDoc is missing @param for "$%s" on symbol "%s".',
                        $name,
                        $symbol,
                    ),
                    confidence: 'medium',
                    subtype: 'signature_param_missing_in_phpdoc',
                    explanation: $explain
                        ? 'Every signature parameter should be documented for stable API expectations.'
                        : null,
                    suggestion: sprintf('Add @param for "$%s".', $name),
                ),

                $nativeType === '' || !isset($docParamTypes[$name]) => null,

                !$this->typesAreCompatible(
                    $docParamTypes[$name],
                    $nativeType,
                ) => $this->signatureFinding(
                    line: $docLine,
                    type: 'phpdoc_signature_mismatch',
                    message: sprintf(
                        'PHPDoc @param type for "$%s" does not match native signature on "%s".',
                        $name,
                        $symbol,
                    ),
                    confidence: 'high',
                    subtype: 'param_type_mismatch',
                    explanation: $explain
                        ? sprintf(
                            'PHPDoc=%s, signature=%s.',
                            $docParamTypes[$name],
                            $nativeType,
                        )
                        : null,
                    suggestion: sprintf(
                        'Update @param "$%s" to match the signature type.',
                        $name,
                    ),
                ),

                default => null,
            };

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
    }

    /**
     * @param Findings $findings
     */
    private function collectReturnSignatureFinding(
        Node\FunctionLike $node,
        PhpDocNode $docNode,
        string $symbol,
        int $docLine,
        bool $explain,
        array &$findings,
    ): void {
        $signatureReturn = $this->normalizeType(
            PhpNodeTypeString::fromNode($node->getReturnType()),
        );

        $returnTags = $docNode->getReturnTagValues();

        if ($signatureReturn === '' || $returnTags === []) {
            return;
        }

        $docReturn = $this->normalizeType(
            (string) $returnTags[0]->type,
        );

        if (
            $docReturn === ''
            || $this->typesAreCompatible($docReturn, $signatureReturn)
        ) {
            return;
        }

        $findings[] = $this->signatureFinding(
            line: $docLine,
            type: 'phpdoc_signature_mismatch',
            message: sprintf(
                'PHPDoc @return type does not match native return type on "%s".',
                $symbol,
            ),
            confidence: 'high',
            subtype: 'return_type_mismatch',
            explanation: $explain
                ? sprintf(
                    'PHPDoc=%s, signature=%s.',
                    $docReturn,
                    $signatureReturn,
                )
                : null,
            suggestion: 'Update @return to match the signature return type.',
        );
    }

    /**
     * @param Findings $findings
     * @param Options $options
     */
    private function collectSignatureFindings(
        Node\FunctionLike $node,
        PhpDocNode $docNode,
        string $symbol,
        int $docLine,
        array &$findings,
        array $options,
    ): void {
        $signatureParams = $this->collectSignatureParameters($node);
        [$docParamTypes, $docParamNames] = $this->collectDocumentedParameters($docNode);

        $explain = ($options['explain'] ?? false) === true;

        $this->collectParameterSignatureFindings(
            $signatureParams,
            $docParamTypes,
            $docParamNames,
            $symbol,
            $docLine,
            $explain,
            $findings,
        );

        $this->collectReturnSignatureFinding(
            $node,
            $docNode,
            $symbol,
            $docLine,
            $explain,
            $findings,
        );
    }

    /**
     * @return array<string, string>
     */
    private function collectSignatureParameters(Node\FunctionLike $node): array
    {
        $parameters = [];

        foreach ($node->getParams() as $parameter) {
            $variable = $parameter->var;

            if (!$variable instanceof Node\Expr\Variable || !is_string($variable->name)) {
                continue;
            }

            $parameters[$variable->name] = $this->normalizeType(
                PhpNodeTypeString::fromNode($parameter->type),
            );
        }

        return $parameters;
    }

    /**
     * @param Findings $findings
     * @param Options $options
     */
    private function collectTypeHygieneFindings(
        PhpDocNode $docNode,
        string $symbol,
        int $docLine,
        array &$findings,
        array $options,
    ): void {
        foreach ($docNode->children as $child) {
            if (!$child instanceof PhpDocTagNode || !$child->value instanceof InvalidTagValueNode) {
                continue;
            }

            $tag = ltrim(strtolower($child->name), '@');
            $raw = trim((string) $child->value);
            $findings[] = [
                'line' => $docLine,
                'end_line' => $docLine,
                'type' => 'phpdoc_invalid_tag_value',
                'message' => sprintf('Invalid %s PHPDoc value on symbol "%s".', $child->name, $symbol),
                'confidence' => 'high',
                'subtype' => 'invalid_' . $tag . '_tag',
                'explanation' => ($options['explain'] ?? false) === true && $raw !== '' ? $raw : null,
                'suggestion' => sprintf('Fix %s to use a valid type/shape expression.', $child->name),
                'raw' => $raw !== '' ? $raw : null,
            ];
        }
    }

    private function normalizeType(string $value): string
    {
        $normalized = strtolower(trim(str_replace(' ', '', $value)));

        if ($normalized === '') {
            return '';
        }

        while (str_starts_with($normalized, '(') && str_ends_with($normalized, ')')) {
            $inner = substr($normalized, 1, -1);
            if ($inner === '') {
                break;
            }
            $normalized = $inner;
        }

        if (str_starts_with($normalized, '?')) {
            $normalized = 'null|' . substr($normalized, 1);
        }

        if (preg_match('/^[a-z0-9_\\\\]+(?:\|[a-z0-9_\\\\]+)+$/', $normalized) === 1) {
            $parts = array_map(static fn(string $part): string => ltrim($part, '\\'), explode('|', $normalized));
            sort($parts);

            return implode('|', array_values(array_unique($parts)));
        }

        return ltrim($normalized, '\\');
    }

    private function phpDocLexer(): Lexer
    {
        if ($this->phpDocLexer instanceof Lexer) {
            return $this->phpDocLexer;
        }

        $this->phpDocLexer = PhpDocParsing::lexer();

        return $this->phpDocLexer;
    }

    private function phpDocParser(): PhpDocParser
    {
        if ($this->phpDocParser instanceof PhpDocParser) {
            return $this->phpDocParser;
        }

        $this->phpDocParser = PhpDocParsing::parser();

        return $this->phpDocParser;
    }

    private function qualifiedName(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * @return Finding
     */
    private function signatureFinding(
        int $line,
        string $type,
        string $message,
        string $confidence,
        string $subtype,
        ?string $explanation,
        string $suggestion,
    ): array {
        return [
            'line' => $line,
            'end_line' => $line,
            'type' => $type,
            'message' => $message,
            'confidence' => $confidence,
            'subtype' => $subtype,
            'explanation' => $explanation,
            'suggestion' => $suggestion,
            'raw' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function topLevelTypeParts(string $type, string $separator): array
    {
        $parts = [];
        $part = '';
        $depth = 0;
        $quote = '';
        $escaped = false;
        $length = strlen($type);

        for ($index = 0; $index < $length; $index++) {
            $character = $type[$index];

            if ($quote !== '') {
                $part .= $character;

                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $part .= $character;

                continue;
            }

            if (str_contains('(<[{', $character)) {
                $depth++;
            } elseif (str_contains(')>]}', $character)) {
                $depth = max(0, $depth - 1);
            }

            if ($character === $separator && $depth === 0) {
                $parts[] = $part;
                $part = '';

                continue;
            }

            $part .= $character;
        }

        $parts[] = $part;

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    private function typesAreCompatible(string $documented, string $native): bool
    {
        if ($documented === $native) {
            return true;
        }

        $nativeTypes = $this->topLevelTypeParts($native, '|');

        foreach ($this->topLevelTypeParts($documented, '|') as $documentedType) {
            $compatible = array_any($nativeTypes, fn($nativeType) => $this->atomicTypeIsCompatible($documentedType, $nativeType));
            if (!$compatible) {
                return false;
            }
        }

        return true;
    }
}

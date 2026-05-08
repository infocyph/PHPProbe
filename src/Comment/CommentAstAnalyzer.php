<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Comment;

use Infocyph\PHPProbe\Util\PhpDocParsing;
use Infocyph\PHPProbe\Util\PhpNodeTypeString;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypelessParamTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;

final class CommentAstAnalyzer
{
    private ?Lexer $phpDocLexer = null;

    private ?PhpDocParser $phpDocParser = null;

    /**
     * @param array<string, mixed> $options
     * @return array{
     *     symbols:list<array{id:string,aliases:list<string>,start_line:int,end_line:int}>,
     *     findings:list<array{
     *         line:int,
     *         end_line:int,
     *         type:string,
     *         message:string,
     *         confidence:string,
     *         subtype:?string,
     *         explanation:?string,
     *         suggestion:?string,
     *         raw:?string
     *     }>
     * }
     */
    public function analyze(string $file, array $options): array
    {
        $contents = file_get_contents($file);

        if (!is_string($contents) || trim($contents) === '') {
            return ['symbols' => [], 'findings' => []];
        }

        try {
            $nodes = (new ParserFactory())->createForHostVersion()->parse($contents);
        } catch (\Throwable) {
            return ['symbols' => [], 'findings' => []];
        }

        if ($nodes === null) {
            return ['symbols' => [], 'findings' => []];
        }

        $symbols = [];
        $findings = [];
        $this->analyzeNodes($nodes, '', $symbols, $findings, $options);

        return ['symbols' => $symbols, 'findings' => $findings];
    }

    /**
     * @param list<array{id:string,aliases:list<string>,start_line:int,end_line:int}> $symbols
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
     * @param list<array{line:int,end_line:int,type:string,message:string,confidence:string,subtype:?string,explanation:?string,suggestion:?string,raw:?string}> $findings
     * @param array<string, mixed> $options
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
     * @param list<Node> $nodes
     * @param list<array{id:string,aliases:list<string>,start_line:int,end_line:int}> $symbols
     * @param list<array{line:int,end_line:int,type:string,message:string,confidence:string,subtype:?string,explanation:?string,suggestion:?string,raw:?string}> $findings
     * @param array<string, mixed> $options
     */
    private function analyzeNodes(
        array $nodes,
        string $namespace,
        array &$symbols,
        array &$findings,
        array $options,
    ): void {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $this->analyzeNodes($node->stmts, $node->name?->toString() ?? '', $symbols, $findings, $options);

                continue;
            }

            if ($node instanceof Node\Stmt\ClassLike) {
                if ($node->name === null) {
                    continue;
                }

                $localClassName = $node->name->toString();
                $fqcn = $this->qualifiedName($namespace, $localClassName);
                $this->addSymbol($symbols, $fqcn, [
                    $fqcn,
                    $localClassName,
                    'class ' . $fqcn,
                    'class ' . $localClassName,
                ], $node->getStartLine(), $node->getEndLine());

                foreach ($node->stmts as $statement) {
                    if ($statement instanceof Node\Stmt\ClassMethod) {
                        $method = $statement->name->toString();
                        $this->addSymbol($symbols, $fqcn . '::' . $method, [
                            $fqcn . '::' . $method,
                            $localClassName . '::' . $method,
                            $method,
                        ], $statement->getStartLine(), $statement->getEndLine());
                        $this->analyzeFunctionLike($statement, $fqcn . '::' . $method, $findings, $options);

                        continue;
                    }

                    if ($statement instanceof Node\Stmt\Property) {
                        foreach ($statement->props as $property) {
                            $name = $property->name->toString();
                            $this->addSymbol($symbols, $fqcn . '::$' . $name, [
                                $fqcn . '::$' . $name,
                                $localClassName . '::$' . $name,
                            ], $statement->getStartLine(), $statement->getEndLine());
                        }

                        continue;
                    }

                    if ($statement instanceof Node\Stmt\ClassConst) {
                        foreach ($statement->consts as $constant) {
                            $name = $constant->name->toString();
                            $this->addSymbol($symbols, $fqcn . '::' . $name, [
                                $fqcn . '::' . $name,
                                $localClassName . '::' . $name,
                            ], $statement->getStartLine(), $statement->getEndLine());
                        }
                    }
                }

                continue;
            }

            if ($node instanceof Node\Stmt\Function_) {
                $function = $node->name->toString();
                $fqfn = $this->qualifiedName($namespace, $function);
                $this->addSymbol($symbols, $fqfn, [
                    $fqfn,
                    $function,
                    'function ' . $fqfn,
                    'function ' . $function,
                ], $node->getStartLine(), $node->getEndLine());
                $this->analyzeFunctionLike($node, $fqfn, $findings, $options);

                continue;
            }

            if ($node instanceof Node\Stmt\Const_) {
                foreach ($node->consts as $constant) {
                    $name = $this->qualifiedName($namespace, $constant->name->toString());
                    $this->addSymbol($symbols, $name, [
                        $name,
                        'constant ' . $name,
                    ], $constant->getStartLine(), $constant->getEndLine());
                }
            }
        }
    }

    /**
     * @param list<array{line:int,end_line:int,type:string,message:string,confidence:string,subtype:?string,explanation:?string,suggestion:?string,raw:?string}> $findings
     * @param array<string, mixed> $options
     */
    private function collectSignatureFindings(
        Node\FunctionLike $node,
        PhpDocNode $docNode,
        string $symbol,
        int $docLine,
        array &$findings,
        array $options,
    ): void {
        $signatureParams = [];

        foreach ($node->getParams() as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            $name = $param->var->name;
            $signatureParams[$name] = $this->normalizeType(PhpNodeTypeString::fromNode($param->type));
        }

        $docParamTypes = [];
        $docParamNames = [];

        foreach ($docNode->getParamTagValues() as $tag) {
            if (!$tag instanceof ParamTagValueNode) {
                continue;
            }

            $name = ltrim($tag->parameterName, '$');
            if ($name === '') {
                continue;
            }

            $docParamNames[$name] = true;
            $docParamTypes[$name] = $this->normalizeType((string) $tag->type);
        }

        foreach ($docNode->getTypelessParamTagValues() as $tag) {
            if (!$tag instanceof TypelessParamTagValueNode) {
                continue;
            }

            $name = ltrim($tag->parameterName, '$');
            if ($name !== '') {
                $docParamNames[$name] = true;
            }
        }

        foreach ($docParamNames as $name => $_present) {
            if (!array_key_exists($name, $signatureParams)) {
                $findings[] = [
                    'line' => $docLine,
                    'end_line' => $docLine,
                    'type' => 'phpdoc_unknown_param',
                    'message' => sprintf('PHPDoc references unknown parameter "$%s" on symbol "%s".', $name, $symbol),
                    'confidence' => 'high',
                    'subtype' => 'param_not_in_signature',
                    'explanation' => ($options['explain'] ?? false) === true
                        ? 'Parameter exists in PHPDoc but not in function signature.'
                        : null,
                    'suggestion' => 'Rename or remove the extra @param tag.',
                    'raw' => null,
                ];
            }
        }

        foreach ($signatureParams as $name => $nativeType) {
            if (!isset($docParamNames[$name])) {
                $findings[] = [
                    'line' => $docLine,
                    'end_line' => $docLine,
                    'type' => 'phpdoc_missing_param',
                    'message' => sprintf('PHPDoc is missing @param for "$%s" on symbol "%s".', $name, $symbol),
                    'confidence' => 'medium',
                    'subtype' => 'signature_param_missing_in_phpdoc',
                    'explanation' => ($options['explain'] ?? false) === true
                        ? 'Every signature parameter should be documented for stable API expectations.'
                        : null,
                    'suggestion' => sprintf('Add @param for "$%s".', $name),
                    'raw' => null,
                ];

                continue;
            }

            if ($nativeType === '' || !isset($docParamTypes[$name])) {
                continue;
            }

            if ($docParamTypes[$name] !== $nativeType) {
                $findings[] = [
                    'line' => $docLine,
                    'end_line' => $docLine,
                    'type' => 'phpdoc_signature_mismatch',
                    'message' => sprintf('PHPDoc @param type for "$%s" does not match native signature on "%s".', $name, $symbol),
                    'confidence' => 'high',
                    'subtype' => 'param_type_mismatch',
                    'explanation' => ($options['explain'] ?? false) === true
                        ? sprintf('PHPDoc=%s, signature=%s.', $docParamTypes[$name], $nativeType)
                        : null,
                    'suggestion' => sprintf('Update @param "$%s" to match the signature type.', $name),
                    'raw' => null,
                ];
            }
        }

        $signatureReturn = $this->normalizeType(PhpNodeTypeString::fromNode($node->getReturnType()));
        $returnTags = $docNode->getReturnTagValues();

        if ($signatureReturn === '' || $returnTags === []) {
            return;
        }

        $returnTag = $returnTags[0];
        if (!$returnTag instanceof ReturnTagValueNode) {
            return;
        }

        $docReturn = $this->normalizeType((string) $returnTag->type);
        if ($docReturn === '' || $docReturn === $signatureReturn) {
            return;
        }

        $findings[] = [
            'line' => $docLine,
            'end_line' => $docLine,
            'type' => 'phpdoc_signature_mismatch',
            'message' => sprintf('PHPDoc @return type does not match native return type on "%s".', $symbol),
            'confidence' => 'high',
            'subtype' => 'return_type_mismatch',
            'explanation' => ($options['explain'] ?? false) === true
                ? sprintf('PHPDoc=%s, signature=%s.', $docReturn, $signatureReturn)
                : null,
            'suggestion' => 'Update @return to match the signature return type.',
            'raw' => null,
        ];
    }

    /**
     * @param list<array{line:int,end_line:int,type:string,message:string,confidence:string,subtype:?string,explanation:?string,suggestion:?string,raw:?string}> $findings
     * @param array<string, mixed> $options
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

}

<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Detection;

/**
 * @phpstan-type Token array{value:string,semantic:string,line:int}
 * @phpstan-type TokenizerState array{
 *     currentLine:int, braceDepth:int, namespaceDepth:int,
 *     namespacePending:bool, statementStart:bool,
 *     skippingImport:bool, previousTokenId:int|null
 * }
 */
final class DuplicateCodeIndex
{
    private const array IGNORED_TOKEN_IDS = [
        T_OPEN_TAG,
        T_CLOSE_TAG,
        T_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
    ];

    private const array PRESERVED_IDENTIFIER_PREDECESSORS = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
    ];

    /**
     * @param list<string> $files
     * @param array{normalize:bool,fuzzy:bool} $options
     * @return array{streams:array<string,list<Token>>,blocks:array<string,list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>>,total_lines:int}
     */
    public function build(array $files, array $options, bool $includeAst): array
    {
        $streams = [];
        $blocks = [];
        $totalLines = 0;
        $ast = $includeAst ? new DuplicateAstBlockIndex() : null;

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if (!is_string($contents)) {
                throw new \RuntimeException(sprintf('Failed to read PHP source file: %s', $file));
            }

            $totalLines += $this->lineCount($contents);
            $tokens = $this->tokenize($contents, $options['normalize'], $options['fuzzy']);
            $streams[$file] = $tokens;

            if ($ast !== null) {
                $blocks[$file] = $ast->blocks($contents, $file, $tokens);
            }
        }

        return ['streams' => $streams, 'blocks' => $blocks, 'total_lines' => $totalLines];
    }

    /**
     * @param array{0:int,1:string,2:int} $rawToken
     * @param list<Token> $tokens
     * @param TokenizerState $state
     */
    private function appendArrayToken(
        array $rawToken,
        array &$tokens,
        array &$state,
        bool $normalize,
        bool $fuzzy,
        ?int $nextTokenId,
    ): void {
        [$id, $text, $line] = $rawToken;
        $state['currentLine'] = $line + substr_count($text, "\n");

        if ($this->shouldSkipArrayToken($id, $state)) {
            return;
        }

        if ($this->startsTopLevelImport($id, $state)) {
            $state['skippingImport'] = true;
            $state['previousTokenId'] = null;

            return;
        }

        if ($id === T_NAMESPACE && $state['statementStart']) {
            $state['namespacePending'] = true;
        }

        $values = $normalize
            ? $this->normalizeToken($id, $text, $fuzzy, $state['previousTokenId'], $nextTokenId)
            : [
                'value' => token_name($id) . ':' . $text,
                'semantic' => token_name($id) . ':' . $text,
            ];

        $tokens[] = [
            ...$values,
            'line' => $line,
        ];

        $state['previousTokenId'] = $id;
        $state['statementStart'] = false;
    }

    /**
     * @param list<Token> $tokens
     * @param TokenizerState $state
     */
    private function appendCharacterToken(string $rawToken, array &$tokens, array &$state): void
    {
        if ($state['skippingImport']) {
            $this->consumeImportCharacter($rawToken, $state);

            return;
        }

        $tokens[] = [
            'value' => $rawToken,
            'semantic' => $rawToken,
            'line' => $state['currentLine'],
        ];

        $state['currentLine'] += substr_count($rawToken, "\n");
        $state['previousTokenId'] = null;
        $this->updateStructuralState($rawToken, $state);
    }

    /** @param TokenizerState $state */
    private function consumeImportCharacter(string $rawToken, array &$state): void
    {
        if ($rawToken === ';') {
            $state['skippingImport'] = false;
            $state['statementStart'] = true;
            $state['previousTokenId'] = null;
        }

        $state['currentLine'] += substr_count($rawToken, "\n");
    }

    private function isIdentifierToken(int $id): bool
    {
        return in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private function lineCount(string $contents): int
    {
        if ($contents === '') {
            return 0;
        }

        $newlines = substr_count($contents, "\n");

        return str_ends_with($contents, "\n") ? $newlines : $newlines + 1;
    }

    /** @return TokenizerState */
    private function newTokenizerState(): array
    {
        return [
            'currentLine' => 1,
            'braceDepth' => 0,
            'namespaceDepth' => 0,
            'namespacePending' => false,
            'statementStart' => true,
            'skippingImport' => false,
            'previousTokenId' => null,
        ];
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $rawTokens
     * @return list<int|null>
     */
    private function nextSignificantTokenIds(array $rawTokens): array
    {
        $nextTokenIds = array_fill(0, count($rawTokens), null);
        $nextTokenId = null;

        for ($index = count($rawTokens) - 1; $index >= 0; $index--) {
            $nextTokenIds[$index] = $nextTokenId;
            $rawToken = $rawTokens[$index];

            if (!is_array($rawToken)) {
                $nextTokenId = null;

                continue;
            }

            $id = $rawToken[0];

            if (!in_array($id, self::IGNORED_TOKEN_IDS, true)) {
                $nextTokenId = $id;
            }
        }

        return array_values($nextTokenIds);
    }

    /** @return array{value:string,semantic:string} */
    private function normalizeToken(
        int $id,
        string $text,
        bool $fuzzy,
        ?int $previousTokenId,
        ?int $nextTokenId,
    ): array {
        if ($this->isIdentifierToken($id)) {
            $value = !$fuzzy || $this->shouldPreserveIdentifier($previousTokenId)
                ? token_name($id) . ':' . strtolower($text)
                : 'ID';

            return ['value' => $value, 'semantic' => $value];
        }

        $value = match ($id) {
            T_VARIABLE => 'VAR',
            T_LNUMBER, T_DNUMBER => 'NUM',
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE => 'STR',
            default => token_name($id) . ':' . strtolower($text),
        };

        $semantic = $fuzzy
            && $id === T_CONSTANT_ENCAPSED_STRING
            && $nextTokenId === T_DOUBLE_ARROW
                ? 'KEY:' . $text
                : $value;

        return ['value' => $value, 'semantic' => $semantic];
    }

    private function shouldPreserveIdentifier(?int $previousTokenId): bool
    {
        return in_array($previousTokenId, self::PRESERVED_IDENTIFIER_PREDECESSORS, true);
    }

    /** @param TokenizerState $state */
    private function shouldSkipArrayToken(int $id, array $state): bool
    {
        return $state['skippingImport'] || in_array($id, self::IGNORED_TOKEN_IDS, true);
    }

    /** @param TokenizerState $state */
    private function startsTopLevelImport(int $id, array $state): bool
    {
        return $id === T_USE
            && $state['statementStart']
            && $state['braceDepth'] === $state['namespaceDepth'];
    }

    /**
     * @return list<Token>
     */
    private function tokenize(string $contents, bool $normalize, bool $fuzzy): array
    {
        $tokens = [];
        $state = $this->newTokenizerState();
        $rawTokens = token_get_all($contents);
        $nextTokenIds = $this->nextSignificantTokenIds($rawTokens);

        foreach ($rawTokens as $index => $rawToken) {
            if (is_array($rawToken)) {
                $this->appendArrayToken(
                    $rawToken,
                    $tokens,
                    $state,
                    $normalize,
                    $fuzzy,
                    $nextTokenIds[$index],
                );

                continue;
            }

            $this->appendCharacterToken($rawToken, $tokens, $state);
        }

        return $tokens;
    }

    /** @param TokenizerState $state */
    private function updateStructuralState(string $rawToken, array &$state): void
    {
        if ($rawToken === '{') {
            $state['braceDepth']++;

            if ($state['namespacePending']) {
                $state['namespaceDepth'] = $state['braceDepth'];
                $state['namespacePending'] = false;
            }

            $state['statementStart'] = true;

            return;
        }

        if ($rawToken === '}') {
            $state['braceDepth'] = max(0, $state['braceDepth'] - 1);

            if ($state['namespaceDepth'] > $state['braceDepth']) {
                $state['namespaceDepth'] = 0;
            }

            $state['statementStart'] = true;

            return;
        }

        if ($rawToken === ';') {
            $state['namespacePending'] = false;
            $state['statementStart'] = true;

            return;
        }

        $state['statementStart'] = false;
    }
}

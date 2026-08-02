<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Detection;

final class DuplicateCodeIndex
{
    /**
     * @param list<string> $files
     * @param array{normalize:bool,fuzzy:bool} $options
     * @return array{streams:array<string,list<array{value:string,line:int}>>,blocks:array<string,list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>>,total_lines:int}
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

    private function normalizeToken(int $id, string $text, bool $fuzzy): string
    {
        if ($this->isIdentifierToken($id)) {
            return $fuzzy ? 'ID' : token_name($id) . ':' . strtolower($text);
        }

        return match ($id) {
            T_VARIABLE => 'VAR',
            T_LNUMBER, T_DNUMBER => 'NUM',
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE => 'STR',
            default => token_name($id) . ':' . strtolower($text),
        };
    }

    /**
     * @return list<array{value:string,line:int}>
     */
    private function tokenize(string $contents, bool $normalize, bool $fuzzy): array
    {
        $tokens = [];
        $currentLine = 1;
        $braceDepth = 0;
        $namespaceDepth = 0;
        $namespacePending = false;
        $statementStart = true;
        $skippingImport = false;

        foreach (token_get_all($contents) as $rawToken) {
            if (is_array($rawToken)) {
                [$id, $text, $line] = $rawToken;
                $currentLine = $line + substr_count($text, "\n");

                if ($skippingImport || in_array($id, [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($id === T_USE && $statementStart && $braceDepth === $namespaceDepth) {
                    $skippingImport = true;

                    continue;
                }

                if ($id === T_NAMESPACE && $statementStart) {
                    $namespacePending = true;
                }

                $tokens[] = [
                    'value' => $normalize ? $this->normalizeToken($id, $text, $fuzzy) : token_name($id) . ':' . $text,
                    'line' => $line,
                ];
                $statementStart = false;

                continue;
            }

            if ($skippingImport) {
                if ($rawToken === ';') {
                    $skippingImport = false;
                    $statementStart = true;
                }

                $currentLine += substr_count($rawToken, "\n");

                continue;
            }

            $tokens[] = ['value' => $rawToken, 'line' => $currentLine];
            $currentLine += substr_count($rawToken, "\n");

            if ($rawToken === '{') {
                $braceDepth++;

                if ($namespacePending) {
                    $namespaceDepth = $braceDepth;
                    $namespacePending = false;
                }

                $statementStart = true;
            } elseif ($rawToken === '}') {
                $braceDepth = max(0, $braceDepth - 1);

                if ($namespaceDepth > $braceDepth) {
                    $namespaceDepth = 0;
                }

                $statementStart = true;
            } elseif ($rawToken === ';') {
                $namespacePending = false;
                $statementStart = true;
            } else {
                $statementStart = false;
            }
        }

        return $tokens;
    }
}

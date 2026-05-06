<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Comment;

final class PhpCommentExtractor
{
    /**
     * @return list<array{type:string,raw:string,line:int,end_line:int}>
     */
    public function extract(string $file): array
    {
        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            return [];
        }

        $comments = [];

        foreach (token_get_all($contents) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $raw = $token[1];
            $line = $token[2];
            $endLine = $line + substr_count($raw, "\n");

            $comments[] = [
                'type' => $token[0] === T_DOC_COMMENT ? 'doc_comment' : $this->commentType($raw),
                'raw' => $raw,
                'line' => $line,
                'end_line' => $endLine,
            ];
        }

        return $comments;
    }

    private function commentType(string $raw): string
    {
        if (str_starts_with($raw, '/*')) {
            return 'block_comment';
        }

        return 'line_comment';
    }
}


<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Comment;

use Infocyph\PHPProbe\Util\ProjectPath;

final class CommentScanner
{
    /**
     * @param list<string> $files
     * @param array{
     *     scanMarkers:bool,
     *     markerTags:list<string>,
     *     markerSeverity:array<string,string>,
     *     commentedOutEnabled:bool,
     *     allowedReasonTags:list<string>,
     *     optionalReasonTags:list<string>,
     *     allowOptionalReasonTagsInStrictMode:bool,
     *     minReasonLength:int,
     *     maxAllowedBlockLines:int,
     *     requireIssueForBlocksLongerThan:int,
     *     allowedIssuePatterns:list<string>,
     *     allowBlankLineBetweenReasonAndCode:bool,
     *     allowReasonBeforeBlockComment:bool,
     *     allowBlankLineBetweenReasonAndCodeInBlock:bool,
     *     allowPhpdocExamples:bool,
     *     phpdocExampleLabels:list<string>,
     *     strict:bool,
     *     typeSeverity:array<string,string>,
     *     strictSeverity:array<string,string>
     * } $options
     * @return array{files:int,findings:list<CommentFinding>}
     */
    public function scan(array $files, array $options): array
    {
        $findings = [];

        foreach ($files as $file) {
            $comments = (new PhpCommentExtractor())->extract($file);
            $findings = [...$findings, ...$this->scanMarkers($file, $comments, $options), ...$this->scanCommentedOutCode($file, $comments, $options)];
        }

        usort(
            $findings,
            static fn(CommentFinding $left, CommentFinding $right): int => [$left->file, $left->line, $left->endLine, $left->type] <=> [$right->file, $right->line, $right->endLine, $right->type],
        );

        return ['files' => count($files), 'findings' => $findings];
    }

    /**
     * @param array{type:string,raw:string,line:int,end_line:int} $comment
     * @return list<array{text:string,line:int}>
     */
    private function commentLines(array $comment): array
    {
        if ($comment['type'] === 'line_comment') {
            return [[
                'text' => $this->normalizeLineComment($comment['raw']),
                'line' => $comment['line'],
            ]];
        }

        return $this->normalizeBlockCommentLines($comment['raw'], $comment['line']);
    }

    private function detectReasonStatus(
        string $file,
        int $startLine,
        int $endLine,
        int $codeLines,
        ?array $reasonCandidate,
        bool $isPhpDoc,
        bool $hasExampleLabel,
        array $options,
    ): ?CommentFinding {
        if ($reasonCandidate === null) {
            if ($isPhpDoc && !$hasExampleLabel) {
                return $this->finding(
                    $file,
                    $startLine,
                    $endLine,
                    'commented_out_code_in_phpdoc_without_example_label',
                    'PHPDoc contains code-like lines without an example label or a tagged reason.',
                    $options,
                );
            }

            return $this->finding(
                $file,
                $startLine,
                $endLine,
                'commented_out_code_without_reason',
                'Commented-out code requires a directly attached tagged reason.',
                $options,
            );
        }

        $parsed = $this->parseMarker($reasonCandidate['text'], $this->allKnownTags($options));

        if ($parsed === null) {
            return $this->finding(
                $file,
                $startLine,
                $endLine,
                'commented_out_code_without_valid_reason',
                'Attached comment is not a valid tagged reason.',
                $options,
                raw: $reasonCandidate['text'],
            );
        }

        if (!$this->isAllowedReasonTag($parsed['tag'], $options)) {
            return $this->finding(
                $file,
                $startLine,
                $endLine,
                'commented_out_code_without_valid_tag',
                sprintf('Tag "%s" is not allowed as a reason for commented-out code.', $parsed['tag']),
                $options,
                tag: $parsed['tag'],
                scope: $parsed['scope'],
                reason: $parsed['message'],
                raw: $reasonCandidate['text'],
            );
        }

        if ($this->isWeakReason($parsed['message'], $options['minReasonLength'])) {
            return $this->finding(
                $file,
                $startLine,
                $endLine,
                'commented_out_code_with_weak_reason',
                'Tagged reason is too weak; provide a clear explanation for why the code is disabled.',
                $options,
                tag: $parsed['tag'],
                scope: $parsed['scope'],
                reason: $parsed['message'],
                raw: $reasonCandidate['text'],
            );
        }

        if ($codeLines > $options['requireIssueForBlocksLongerThan'] && !$this->hasIssueReference($parsed, $options['allowedIssuePatterns'])) {
            return $this->finding(
                $file,
                $startLine,
                $endLine,
                'commented_out_code_requires_issue_reference',
                'Commented-out code blocks longer than the configured threshold must include an issue reference.',
                $options,
                tag: $parsed['tag'],
                scope: $parsed['scope'],
                reason: $parsed['message'],
                raw: $reasonCandidate['text'],
            );
        }

        return $this->finding(
            $file,
            $startLine,
            $endLine,
            'commented_out_code_with_valid_reason',
            'Commented-out code is attached to a valid tagged reason.',
            $options,
            tag: $parsed['tag'],
            scope: $parsed['scope'],
            reason: $parsed['message'],
            raw: $reasonCandidate['text'],
        );
    }

    /**
     * @param array{
     *     tag:string,
     *     scope:?string,
     *     message:string
     * } $parsed
     * @param list<string> $patterns
     */
    private function hasIssueReference(array $parsed, array $patterns): bool
    {
        $candidate = trim(($parsed['scope'] ?? '') . ' ' . $parsed['message']);

        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tags
     * @return array{tag:string,scope:?string,message:string}|null
     */
    private function parseMarker(string $text, array $tags): ?array
    {
        if ($tags === []) {
            return null;
        }

        $escaped = array_map(static fn(string $tag): string => preg_quote($tag, '/'), $tags);
        $pattern = '/^\s*(' . implode('|', $escaped) . ')(?:\(([^)]*)\))?:?\s*(.*)$/i';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        return [
            'tag' => strtoupper(trim($matches[1])),
            'scope' => isset($matches[2]) && trim($matches[2]) !== '' ? trim($matches[2]) : null,
            'message' => trim($matches[3] ?? ''),
        ];
    }

    /**
     * @param list<array{text:string,line:int}> $lines
     * @param list<string> $labels
     */
    private function phpDocHasExampleLabel(array $lines, array $labels): bool
    {
        foreach ($lines as $line) {
            if ($this->isExampleLabel($line['text'], $labels)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $labels
     */
    private function isExampleLabel(string $text, array $labels): bool
    {
        $normalizedText = strtolower(trim($text));

        if ($normalizedText === '') {
            return false;
        }

        foreach ($labels as $label) {
            if ($normalizedText === strtolower(trim($label))) {
                return true;
            }
        }

        return false;
    }

    private function finding(
        string $file,
        int $line,
        int $endLine,
        string $type,
        string $message,
        array $options,
        ?string $tag = null,
        ?string $scope = null,
        ?string $issue = null,
        ?string $owner = null,
        ?string $reason = null,
        ?string $raw = null,
    ): CommentFinding {
        return new CommentFinding(
            file: $this->relativePath($file),
            line: $line,
            endLine: $endLine,
            type: $type,
            severity: $this->typeSeverity($type, $options),
            message: $message,
            tag: $tag,
            scope: $scope,
            issue: $issue,
            owner: $owner,
            reason: $reason,
            raw: $raw,
        );
    }

    /**
     * @param array{type:string,raw:string,line:int,end_line:int} $comment
     * @return list<array{text:string,line:int}>
     */
    private function lastMeaningfulCommentLine(array $comment): array
    {
        $lines = $this->commentLines($comment);

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            if (trim($lines[$index]['text']) !== '') {
                return [$lines[$index]];
            }
        }

        return [];
    }

    /**
     * @param list<array{type:string,raw:string,line:int,end_line:int}> $comments
     * @param array{
     *     commentedOutEnabled:bool,
     *     allowBlankLineBetweenReasonAndCode:bool,
     *     allowReasonBeforeBlockComment:bool,
     *     allowBlankLineBetweenReasonAndCodeInBlock:bool,
     *     allowPhpdocExamples:bool,
     *     phpdocExampleLabels:list<string>,
     *     maxAllowedBlockLines:int
     * } $options
     * @return list<CommentFinding>
     */
    private function scanCommentedOutCode(string $file, array $comments, array $options): array
    {
        if (!$options['commentedOutEnabled']) {
            return [];
        }

        return [
            ...$this->scanLineCommentedCode($file, $comments, $options),
            ...$this->scanBlockCommentedCode($file, $comments, $options),
        ];
    }

    /**
     * @param list<array{type:string,raw:string,line:int,end_line:int}> $comments
     * @return list<CommentFinding>
     */
    private function scanBlockCommentedCode(string $file, array $comments, array $options): array
    {
        $findings = [];
        $commentCount = count($comments);

        for ($index = 0; $index < $commentCount; $index++) {
            $comment = $comments[$index];

            if (!in_array($comment['type'], ['block_comment', 'doc_comment'], true)) {
                continue;
            }

            $lines = $this->commentLines($comment);
            $groups = $this->codeGroups($lines);

            if ($groups === []) {
                continue;
            }

            $hasExampleLabel = $comment['type'] === 'doc_comment'
                && $options['allowPhpdocExamples']
                && $this->phpDocHasExampleLabel($lines, $options['phpdocExampleLabels']);

            foreach ($groups as $group) {
                $reasonCandidate = $this->reasonBeforeIndex($lines, $group['start_index'], $options['allowBlankLineBetweenReasonAndCodeInBlock']);

                if ($reasonCandidate === null && $options['allowReasonBeforeBlockComment']) {
                    $previous = $comments[$index - 1] ?? null;

                    if (is_array($previous) && ($previous['end_line'] + 1) === $comment['line']) {
                        $reasonCandidate = $this->lastMeaningfulCommentLine($previous)[0] ?? null;
                    }
                }

                if ($comment['type'] === 'doc_comment'
                    && $hasExampleLabel
                    && ($reasonCandidate === null || $this->isExampleLabel($reasonCandidate['text'], $options['phpdocExampleLabels']))) {
                    continue;
                }

                $reasonFinding = $this->detectReasonStatus(
                    $file,
                    $group['start_line'],
                    $group['end_line'],
                    $group['lines'],
                    $reasonCandidate,
                    $comment['type'] === 'doc_comment',
                    $hasExampleLabel,
                    $options,
                );

                if ($reasonFinding !== null && !($hasExampleLabel && $reasonFinding->type === 'commented_out_code_in_phpdoc_without_example_label')) {
                    $findings[] = $reasonFinding;
                }

                if ($group['lines'] > $options['maxAllowedBlockLines']) {
                    $findings[] = $this->finding(
                        $file,
                        $group['start_line'],
                        $group['end_line'],
                        'commented_out_code_block_too_large',
                        sprintf(
                            'Commented-out code block has %d lines; maximum allowed is %d.',
                            $group['lines'],
                            $options['maxAllowedBlockLines'],
                        ),
                        $options,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<array{type:string,raw:string,line:int,end_line:int}> $comments
     * @return list<CommentFinding>
     */
    private function scanLineCommentedCode(string $file, array $comments, array $options): array
    {
        $entries = [];

        foreach ($comments as $comment) {
            if ($comment['type'] !== 'line_comment') {
                continue;
            }

            $entries[] = [
                'line' => $comment['line'],
                'text' => $this->normalizeLineComment($comment['raw']),
                'raw' => $comment['raw'],
            ];
        }

        usort($entries, static fn(array $left, array $right): int => $left['line'] <=> $right['line']);
        $entryByLine = [];

        foreach ($entries as $entry) {
            $entryByLine[$entry['line']] = $entry;
        }

        $findings = [];

        foreach ($this->groupAdjacentCodeLines($entries) as $group) {
            $reasonCandidate = null;
            $reasonLine = $group['start_line'] - 1;

            if (isset($entryByLine[$reasonLine]) && ($options['allowBlankLineBetweenReasonAndCode'] || $reasonLine === ($group['start_line'] - 1))) {
                if (trim($entryByLine[$reasonLine]['text']) !== '') {
                    $reasonCandidate = ['text' => $entryByLine[$reasonLine]['text'], 'line' => $reasonLine];
                }
            }

            $reasonFinding = $this->detectReasonStatus(
                $file,
                $group['start_line'],
                $group['end_line'],
                $group['lines'],
                $reasonCandidate,
                false,
                false,
                $options,
            );

            if ($reasonFinding !== null) {
                $findings[] = $reasonFinding;
            }

            if ($group['lines'] > $options['maxAllowedBlockLines']) {
                $findings[] = $this->finding(
                    $file,
                    $group['start_line'],
                    $group['end_line'],
                    'commented_out_code_block_too_large',
                    sprintf(
                        'Commented-out code block has %d lines; maximum allowed is %d.',
                        $group['lines'],
                        $options['maxAllowedBlockLines'],
                    ),
                    $options,
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array{type:string,raw:string,line:int,end_line:int}> $comments
     * @param array{
     *     scanMarkers:bool,
     *     markerTags:list<string>,
     *     markerSeverity:array<string,string>
     * } $options
     * @return list<CommentFinding>
     */
    private function scanMarkers(string $file, array $comments, array $options): array
    {
        if (!$options['scanMarkers']) {
            return [];
        }

        $findings = [];

        foreach ($comments as $comment) {
            foreach ($this->commentLines($comment) as $line) {
                $parsed = $this->parseMarker($line['text'], $options['markerTags']);

                if ($parsed === null) {
                    continue;
                }

                $severity = $options['markerSeverity'][$parsed['tag']] ?? 'info';

                $findings[] = new CommentFinding(
                    file: $this->relativePath($file),
                    line: $line['line'],
                    endLine: $line['line'],
                    type: 'comment_marker',
                    severity: $severity,
                    message: sprintf('Detected %s marker.', $parsed['tag']),
                    tag: $parsed['tag'],
                    scope: $parsed['scope'],
                    reason: $parsed['message'],
                    raw: $line['text'],
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array{text:string,line:int}> $lines
     * @return list<array{start_line:int,end_line:int,lines:int,start_index:int}>
     */
    private function codeGroups(array $lines): array
    {
        return $this->groupAdjacentCodeLines($lines);
    }

    /**
     * @param list<array{text:string,line:int}> $lines
     * @return list<array{start_line:int,end_line:int,lines:int,start_index:int}>
     */
    private function groupAdjacentCodeLines(array $lines): array
    {
        $groups = [];
        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; $index++) {
            if (!$this->looksLikeCode($lines[$index]['text'])) {
                continue;
            }

            $start = $index;
            $end = $index;

            while (($end + 1) < $lineCount
                && $this->looksLikeCode($lines[$end + 1]['text'])
                && ($lines[$end + 1]['line'] === ($lines[$end]['line'] + 1))) {
                $end++;
            }

            $groups[] = [
                'start_line' => $lines[$start]['line'],
                'end_line' => $lines[$end]['line'],
                'lines' => $end - $start + 1,
                'start_index' => $start,
            ];

            $index = $end;
        }

        return $groups;
    }

    /**
     * @param array{text:string,line:int} $line
     */
    private function isLikelyDocumentationLine(array $line): bool
    {
        $text = trim($line['text']);

        if ($text === '') {
            return true;
        }

        return str_starts_with($text, '@');
    }

    /**
     * @param array{
     *     allowedReasonTags:list<string>,
     *     optionalReasonTags:list<string>,
     *     strict:bool,
     *     allowOptionalReasonTagsInStrictMode:bool
     * } $options
     */
    private function isAllowedReasonTag(string $tag, array $options): bool
    {
        if (in_array($tag, $options['allowedReasonTags'], true)) {
            return true;
        }

        if (!in_array($tag, $options['optionalReasonTags'], true)) {
            return false;
        }

        if ($options['strict'] && !$options['allowOptionalReasonTagsInStrictMode']) {
            return false;
        }

        return true;
    }

    private function isWeakReason(string $message, int $minReasonLength): bool
    {
        $reason = trim($message);

        if ($reason === '' || strlen($reason) < $minReasonLength) {
            return true;
        }

        $normalized = strtolower($reason);
        $weakPhrases = [
            'todo',
            'later',
            'for now',
            'old code',
            'disabled',
            'disabled for now',
            'temporary',
            'temp',
            'testing',
            'test',
            'fix later',
            'wip',
        ];

        foreach ($weakPhrases as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase . ' ')) {
                return true;
            }
        }

        return str_word_count($reason) < 3;
    }

    private function looksLikeCode(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^(TODO|FIXME|BUG|HACK|XXX|NOTE|OPTIMIZE|REFACTOR|DEPRECATED|SECURITY|REVIEW|QUESTION|WARNING)\b/i', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/^\s*@\w+/', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/^\s*(example|examples|usage|snippet|code sample)\s*:/i', $trimmed) === 1) {
            return false;
        }

        // Skip common PHPDoc shape/type lines such as "file:string,".
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*:\s*[^;]+,?$/', $trimmed) === 1) {
            return false;
        }

        // Standalone braces in docs are often type-shape delimiters, not commented-out code.
        if ($trimmed === '{' || $trimmed === '}') {
            return false;
        }

        $patterns = [
            '/^\s*\$[A-Za-z_][A-Za-z0-9_]*\s*=/',
            '/^\s*(if|else|elseif|foreach|for|while|switch|try|catch|finally)\b/',
            '/^\s*(return|throw|new|class|interface|trait|enum|function|namespace|use)\b/',
            '/->|::/',
            '/;\s*$/',
            '/<\?php\b/',
            '/^\s*[A-Za-z_][A-Za-z0-9_]*\s*\(/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLineComment(string $raw): string
    {
        $text = preg_replace('/^\s*(\/\/+|#)\s?/', '', $raw);

        return is_string($text) ? trim($text) : trim($raw);
    }

    /**
     * @return list<array{text:string,line:int}>
     */
    private function normalizeBlockCommentLines(string $raw, int $startLine): array
    {
        $rawLines = preg_split('/\R/', $raw) ?: [];
        $lineCount = count($rawLines);
        $normalized = [];

        foreach ($rawLines as $index => $line) {
            $content = $line;

            if ($index === 0) {
                $content = preg_replace('/^\s*\/\*\*?/', '', $content) ?? $content;
            }

            if ($index === $lineCount - 1) {
                $content = preg_replace('/\*\/\s*$/', '', $content) ?? $content;
            }

            $content = ltrim($content);

            if (str_starts_with($content, '*')) {
                $content = ltrim(substr($content, 1));
            }

            $normalized[] = [
                'text' => trim($content),
                'line' => $startLine + $index,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{text:string,line:int}> $lines
     * @return array{text:string,line:int}|null
     */
    private function reasonBeforeIndex(array $lines, int $index, bool $allowBlankLine): ?array
    {
        if ($index <= 0) {
            return null;
        }

        if (!$allowBlankLine) {
            return trim($lines[$index - 1]['text']) !== '' ? $lines[$index - 1] : null;
        }

        for ($scan = $index - 1; $scan >= 0; $scan--) {
            if (trim($lines[$scan]['text']) !== '') {
                return $lines[$scan];
            }
        }

        return null;
    }

    private function relativePath(string $path): string
    {
        return ProjectPath::relative($path);
    }

    /**
     * @param array{
     *     markerTags:list<string>,
     *     optionalReasonTags:list<string>
     * } $options
     * @return list<string>
     */
    private function allKnownTags(array $options): array
    {
        return array_values(array_unique([...$options['markerTags'], ...$options['optionalReasonTags']]));
    }

    private function typeSeverity(string $type, array $options): string
    {
        if ($options['strict'] && isset($options['strictSeverity'][$type])) {
            return $options['strictSeverity'][$type];
        }

        return $options['typeSeverity'][$type] ?? 'info';
    }
}

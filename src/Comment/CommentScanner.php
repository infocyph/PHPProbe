<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Comment;

use Infocyph\PHPProbe\Util\AtomicFileWriter;
use Infocyph\PHPProbe\Util\PhpDocParsing;
use Infocyph\PHPProbe\Util\ProjectPath;
use Infocyph\PHPProbe\Util\ScopedTempFile;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;

/**
 * @phpstan-type CommentLine array{text:string,line:int}
 * @phpstan-type ExtractedComment array{type:string,raw:string,line:int,end_line:int}
 * @phpstan-type NormalizedComment array{type:string,raw:string,line:int,end_line:int,lines:list<CommentLine>,parser_fallback:bool,has_phpdoc_tags:bool}
 * @phpstan-type CustomRule array{id:string,pattern:string,severity:string,message:string,enabled:bool,scope:string}
 * @phpstan-type ScannerOptions array{
 *     strict:bool,
 *     explain:bool,
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
 *     typeSeverity:array<string,string>,
 *     strictSeverity:array<string,string>,
 *     suppressionEnabled:bool,
 *     suppressionDirective:string,
 *     ruleEnabled:array<string,bool>,
 *     ruleSeverity:array<string,string>,
 *     customRules:list<CustomRule>,
 *     docMode:string,
 *     docCacheEnabled:bool,
 *     docCacheFile:string
 * }
 * @phpstan-type AstFinding array{line:int,end_line:int,type:string,message:string,confidence:string,subtype:?string,explanation:?string,suggestion:?string,raw:?string}
 * @phpstan-type Marker array{tag:string,scope:?string,message:string}
 * @phpstan-type CodeGroup array{start_line:int,end_line:int,lines:int,start_index:int}
 * @phpstan-type PhpDocParse array{parsed:bool,node:?PhpDocNode,has_tags:bool,parse_error:?string,text_parts:list<string>}
 */
final class CommentScanner
{
    private const string SUBTYPE_DOC_PROSE_MISREAD = 'doc_prose_misread';

    private const string SUBTYPE_SNIPPET_INVALID_REASON_FORMAT = 'snippet_invalid_reason_format';

    private bool $docCacheDirty = false;

    private bool $docCacheEnabled = false;

    private string $docCacheFile;

    private bool $docCacheLoaded = false;

    /** @var array<string, string> */
    private array $markerPatternCache = [];

    /** @var array<string, array{parsed:bool,has_tags:bool,parse_error:?string,text_parts:list<string>}> */
    private array $persistentPhpDocCache = [];

    /** @var array<string, PhpDocParse> */
    private array $phpDocCache = [];

    private ?Lexer $phpDocLexer = null;

    private ?PhpDocParser $phpDocParser = null;

    public function __construct()
    {
        $this->docCacheFile = self::defaultDocCacheFile();
    }

    /**
     * @param list<string> $files
     * @param ScannerOptions $options
     * @return array{files:int,findings:list<CommentFinding>,suppressed_count:int}
     */
    public function scan(array $files, array $options): array
    {
        $options = $this->normalizeOptions($options);
        $this->prepareDocCache($options);
        $findings = [];
        $suppressedCount = 0;
        $ast = new CommentAstAnalyzer();
        $extractor = new PhpCommentExtractor();

        foreach ($files as $file) {
            $astContext = $ast->analyze($file, $options);
            $comments = $this->normalizeComments($extractor->extract($file), $options);
            $fileFindings = [
                ...$this->scanMarkers($file, $comments, $options),
                ...$this->scanCustomRules($file, $comments, $options),
                ...$this->scanCommentedOutCode($file, $comments, $options),
                ...$this->astFindings($file, $astContext['findings'], $options),
            ];
            ['findings' => $activeFindings, 'suppressed' => $suppressed] = $this->applySuppressions($file, $comments, $fileFindings, $options, $astContext['symbols']);
            $suppressedCount += $suppressed;
            $findings = [...$findings, ...$activeFindings];
        }

        $findings = $this->applyRuleOverrides($findings, $options);
        usort($findings, static fn(CommentFinding $a, CommentFinding $b): int => [$a->file, $a->line, $a->endLine, $a->type] <=> [$b->file, $b->line, $b->endLine, $b->type]);
        $this->persistDocCache();

        return ['files' => count($files), 'findings' => $findings, 'suppressed_count' => $suppressedCount];
    }

    private static function defaultDocCacheFile(): string
    {
        return ScopedTempFile::forProject('.phpprobe-comments-doc-cache.json', '.phpprobe-comments-doc-cache');
    }

    /**
     * @param ScannerOptions $options
     * @return list<string>
     */
    private function allKnownTags(array $options): array
    {
        return array_values(array_unique([...$options['allowedReasonTags'], ...$options['optionalReasonTags']]));
    }

    /**
     * @param list<CommentFinding> $findings
     * @param ScannerOptions $options
     * @return list<CommentFinding>
     */
    private function applyRuleOverrides(array $findings, array $options): array
    {
        $normalized = [];

        foreach ($findings as $finding) {
            if (($options['ruleEnabled'][$finding->type] ?? true) !== true) {
                continue;
            }

            $override = $options['ruleSeverity'][$finding->type] ?? null;

            if (!is_string($override) || trim($override) === '') {
                $normalized[] = $finding;

                continue;
            }

            $normalized[] = new CommentFinding(
                file: $finding->file,
                line: $finding->line,
                endLine: $finding->endLine,
                type: $finding->type,
                severity: strtolower(trim($override)),
                message: $finding->message,
                confidence: $finding->confidence,
                subtype: $finding->subtype,
                explanation: $finding->explanation,
                suggestion: $finding->suggestion,
                tag: $finding->tag,
                scope: $finding->scope,
                issue: $finding->issue,
                owner: $finding->owner,
                reason: $finding->reason,
                raw: $finding->raw,
            );
        }

        return $normalized;
    }

    /**
     * @param list<NormalizedComment> $comments
     * @param list<CommentFinding> $findings
     * @param ScannerOptions $options
     * @param list<array{id:string,aliases:list<string>,start_line:int,end_line:int}> $symbols
     * @return array{findings:list<CommentFinding>,suppressed:int}
     */
    private function applySuppressions(string $file, array $comments, array $findings, array $options, array $symbols): array
    {
        if (!$options['suppressionEnabled']) {
            return ['findings' => $findings, 'suppressed' => 0];
        }

        $directive = $options['suppressionDirective'];
        $entries = [];
        $knownRules = $this->knownSuppressionRules($options);
        $invalids = [];
        $today = new \DateTimeImmutable('today');

        foreach ($comments as $comment) {
            foreach ($comment['lines'] as $line) {
                if (!str_contains((string) $line['text'], (string) $directive)) {
                    continue;
                }

                $parsed = $this->parseSuppressionDirective($line['text'], $directive);

                if ($parsed['valid'] !== true) {
                    $invalids[] = $this->finding(
                        $file,
                        $line['line'],
                        $line['line'],
                        'invalid_suppression_rule',
                        sprintf(
                            'Invalid suppression directive format. Expected "%s RULE_ID[,RULE_ID] [until=YYYY-MM-DD] [scope=symbol] [symbol=Name]".',
                            $directive,
                        ),
                        $options,
                        raw: $line['text'],
                        confidence: 'high',
                        subtype: 'suppression_format_invalid',
                        explanation: $options['explain'] ? ($parsed['error'] ?? 'Suppression directive did not match the accepted syntax.') : null,
                        suggestion: 'Use @phpprobe-ignore commented_out_code_without_reason until=2026-12-31',
                    );

                    continue;
                }

                $rules = [];
                foreach ($parsed['rules'] as $token) {
                    if ($token === '*') {
                        $rules[] = '*';

                        continue;
                    }

                    if (!in_array($token, $knownRules, true)) {
                        $invalids[] = $this->finding(
                            $file,
                            $line['line'],
                            $line['line'],
                            'invalid_suppression_rule',
                            sprintf('Unknown suppression rule id "%s".', $token),
                            $options,
                            raw: $line['text'],
                            confidence: 'high',
                            subtype: 'suppression_rule_unknown',
                            explanation: $options['explain'] ? 'Directive referenced a rule that does not exist.' : null,
                            suggestion: sprintf('Use one of: %s', implode(', ', $knownRules)),
                        );

                        continue;
                    }

                    $rules[] = $token;
                }

                if ($rules === []) {
                    continue;
                }

                if ($parsed['until'] !== null) {
                    $expiresOn = \DateTimeImmutable::createFromFormat('Y-m-d', $parsed['until']) ?: null;

                    if ($expiresOn === null) {
                        $invalids[] = $this->finding(
                            $file,
                            $line['line'],
                            $line['line'],
                            'invalid_suppression_rule',
                            'Suppression expiry date must use YYYY-MM-DD format.',
                            $options,
                            raw: $line['text'],
                            confidence: 'high',
                            subtype: 'suppression_expiry_invalid',
                            explanation: $options['explain'] ? 'Could not parse `until` date in suppression directive.' : null,
                            suggestion: 'Use until=2026-12-31.',
                        );

                        continue;
                    }

                    if ($today > $expiresOn) {
                        $invalids[] = $this->finding(
                            $file,
                            $line['line'],
                            $line['line'],
                            'expired_suppression_rule',
                            sprintf('Suppression expired on %s and is no longer active.', $expiresOn->format('Y-m-d')),
                            $options,
                            raw: $line['text'],
                            confidence: 'high',
                            subtype: 'suppression_expired',
                            explanation: $options['explain'] ? 'Directive has an expiry date in the past.' : null,
                            suggestion: 'Remove suppression and resolve the finding, or extend the expiry with review.',
                        );

                        continue;
                    }
                }

                $range = $this->suppressionRange(
                    $line['line'],
                    $parsed['scope'] ?? null,
                    $parsed['symbol'] ?? null,
                    $symbols,
                );

                if ($range === null) {
                    $invalids[] = $this->finding(
                        $file,
                        $line['line'],
                        $line['line'],
                        'invalid_suppression_rule',
                        'Symbol-scoped suppression could not resolve a matching symbol range.',
                        $options,
                        raw: $line['text'],
                        confidence: 'high',
                        subtype: 'suppression_symbol_unresolved',
                        explanation: $options['explain'] ? 'Use scope=symbol on/near a class/function/method or specify symbol=ClassName::method.' : null,
                        suggestion: 'Use symbol=<ClassName::method> or move directive to the target symbol.',
                    );

                    continue;
                }

                $entries[] = [
                    'line' => $range['start_line'],
                    'end_line' => $range['end_line'],
                    'rules' => $rules,
                    'directive_line' => $line['line'],
                    'used' => false,
                ];
            }
        }

        $active = [];
        $suppressed = 0;

        foreach ($findings as $finding) {
            $matched = false;

            foreach ($entries as $index => $entry) {
                if ($finding->line < $entry['line'] || $finding->line > $entry['end_line']) {
                    continue;
                }

                if (in_array('*', $entry['rules'], true) || in_array($finding->type, $entry['rules'], true)) {
                    $matched = true;
                    $suppressed++;
                    $entries[$index]['used'] = true;

                    break;
                }
            }

            if (!$matched) {
                $active[] = $finding;
            }
        }

        foreach ($entries as $entry) {
            if ($entry['used'] === true) {
                continue;
            }

            $active[] = $this->finding(
                $file,
                $entry['directive_line'],
                $entry['directive_line'],
                'dead_suppression_rule',
                'Suppression directive did not match any active finding.',
                $options,
                confidence: 'high',
                subtype: 'suppression_unused',
                explanation: $options['explain'] ? 'Remove stale suppressions to keep comment policy governance explicit.' : null,
                suggestion: 'Delete this suppression or narrow it to a rule that still triggers.',
            );
        }

        return ['findings' => [...$active, ...$invalids], 'suppressed' => $suppressed];
    }

    /**
     * @param list<AstFinding> $items
     * @param ScannerOptions $options
     * @return list<CommentFinding>
     */
    private function astFindings(string $file, array $items, array $options): array
    {
        $findings = [];

        foreach ($items as $item) {
            $findings[] = $this->finding(
                $file,
                $item['line'],
                $item['end_line'],
                $item['type'],
                $item['message'],
                $options,
                raw: $item['raw'],
                confidence: $item['confidence'],
                subtype: $item['subtype'],
                explanation: $item['explanation'],
                suggestion: $item['suggestion'],
            );
        }

        return $findings;
    }

    /**
     * @param list<CommentLine> $lines
     * @return list<CodeGroup>
     */
    private function codeGroups(array $lines): array
    {
        return $this->groupAdjacentCodeLines($lines);
    }

    /**
     * @param ExtractedComment $comment
     * @return list<CommentLine>
     */
    private function commentLines(array $comment): array
    {
        if ($comment['type'] === 'line_comment') {
            return [['text' => $this->normalizeLineComment($comment['raw']), 'line' => $comment['line']]];
        }

        return $this->normalizeBlockCommentLines($comment['raw'], $comment['line']);
    }

    /**
     * @param CommentLine|null $reasonCandidate
     * @param ScannerOptions $options
     */
    private function detectReasonStatus(
        string $file,
        int $startLine,
        int $endLine,
        int $codeLines,
        ?array $reasonCandidate,
        string $source,
        bool $hasExampleLabel,
        bool $parserFallback,
        array $options,
    ): \Infocyph\PHPProbe\Comment\CommentFinding {
        $isDoc = $source === 'doc';
        $fallbackExplain = $parserFallback ? 'PHPDoc parser fallback was used for this comment.' : null;

        if ($reasonCandidate === null) {
            if ($isDoc && !$hasExampleLabel) {
                return $this->finding(
                    $file,
                    $startLine,
                    $endLine,
                    'commented_out_code_in_phpdoc_without_example_label',
                    'PHPDoc contains code-like lines without an example label or a tagged reason.',
                    $options,
                    confidence: $parserFallback ? 'low' : 'medium',
                    subtype: 'doc_snippet_without_example_label',
                    explanation: $options['explain'] ? sprintf('Detected %d code-like line(s) in PHPDoc. %s', $codeLines, $fallbackExplain ?? '') : null,
                    suggestion: 'Add an example label such as "Usage:" or add a tagged reason above the snippet.',
                );
            }

            return $this->finding(
                $file,
                $startLine,
                $endLine,
                'commented_out_code_without_reason',
                'Commented-out code requires a directly attached tagged reason.',
                $options,
                confidence: $isDoc ? ($parserFallback ? 'low' : 'medium') : 'high',
                subtype: $source === 'line' ? 'line_snippet_without_reason' : ($source === 'doc' ? 'doc_snippet_without_reason' : 'block_snippet_without_reason'),
                explanation: $options['explain'] ? sprintf('Detected %d code-like line(s); no tagged reason was found. %s', $codeLines, $fallbackExplain ?? '') : null,
                suggestion: 'Add a reason line like: TODO(#123): explain why code is disabled and when it will be restored.',
            );
        }

        $parsed = $this->parseMarker($reasonCandidate['text'], $this->allKnownTags($options));
        if ($parsed === null) {
            $subtype = $isDoc && $this->isLikelyDocumentationLine($reasonCandidate['text'])
                ? self::SUBTYPE_DOC_PROSE_MISREAD
                : self::SUBTYPE_SNIPPET_INVALID_REASON_FORMAT;

            return $this->finding(
                $file,
                $startLine,
                $endLine,
                'commented_out_code_without_valid_reason',
                'Attached comment is not a valid tagged reason.',
                $options,
                raw: $reasonCandidate['text'],
                confidence: $subtype === 'doc_prose_misread' ? 'low' : ($isDoc ? 'medium' : 'high'),
                subtype: $subtype,
                explanation: $options['explain'] ? sprintf('Reason candidate "%s" does not match TAG(scope): message pattern.', trim((string) $reasonCandidate['text'])) : null,
                suggestion: 'Use a tagged reason, e.g. TODO(auth): explain why disabled and restoration criteria.',
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
                confidence: $isDoc ? 'medium' : 'high',
                subtype: 'snippet_invalid_reason_tag',
                explanation: $options['explain'] ? sprintf('Parsed reason tag "%s" is not in allowed_reason_tags.', $parsed['tag']) : null,
                suggestion: 'Use one of the allowed reason tags (TODO, FIXME, BUG, HACK, SECURITY, REVIEW, DEPRECATED).',
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
                confidence: $isDoc ? 'medium' : 'high',
                subtype: 'snippet_weak_reason',
                explanation: $options['explain'] ? sprintf('Reason text quality check failed (min_reason_length=%d).', $options['minReasonLength']) : null,
                suggestion: 'Expand the reason with concrete cause, risk, and restoration condition.',
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
                confidence: 'high',
                subtype: 'snippet_requires_issue_reference',
                explanation: $options['explain'] ? sprintf('Snippet has %d line(s), threshold is %d, and reason has no issue key match.', $codeLines, $options['requireIssueForBlocksLongerThan']) : null,
                suggestion: 'Include issue ref in scope/message, e.g. TODO(PROJ-123): ...',
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
            confidence: 'high',
            subtype: 'snippet_valid_reason',
            explanation: $options['explain'] ? 'Reason tag, strength, and issue-reference policy checks passed.' : null,
        );
    }

    /**
     * @param NormalizedComment $comment
     * @param ScannerOptions $options
     * @return array{skip:bool,lines:list<CommentLine>,parser_fallback:bool}
     */
    private function effectiveCommentAnalysis(array $comment, array $options): array
    {
        if ($comment['type'] !== 'doc_comment') {
            return ['skip' => false, 'lines' => $comment['lines'], 'parser_fallback' => false];
        }

        if ($options['docMode'] === 'heuristic') {
            return ['skip' => false, 'lines' => $comment['lines'], 'parser_fallback' => false];
        }

        $parsed = $this->parsePhpDoc($comment['raw']);
        $hasExampleLabel = $options['allowPhpdocExamples'] && $this->phpDocHasExampleLabel($comment['lines'], $options['phpdocExampleLabels']);

        if ($parsed['parsed'] && !$hasExampleLabel) {
            $textLines = $this->phpDocTextLines($parsed['node'], $comment['line'], $parsed['text_parts']);
            $codeGroups = $this->codeGroups($textLines);

            if ($parsed['has_tags'] && $codeGroups === []) {
                return ['skip' => true, 'lines' => $comment['lines'], 'parser_fallback' => false];
            }

            if ($codeGroups !== []) {
                return ['skip' => false, 'lines' => $textLines, 'parser_fallback' => false];
            }
        }

        if ($options['docMode'] === 'parser' && !$parsed['parsed']) {
            return ['skip' => false, 'lines' => $comment['lines'], 'parser_fallback' => true];
        }

        return ['skip' => false, 'lines' => $comment['lines'], 'parser_fallback' => !$parsed['parsed']];
    }

    /** @param ScannerOptions $options */
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
        string $confidence = 'medium',
        ?string $subtype = null,
        ?string $explanation = null,
        ?string $suggestion = null,
    ): CommentFinding {
        return new CommentFinding(
            file: $this->relativePath($file),
            line: $line,
            endLine: $endLine,
            type: $type,
            severity: $this->typeSeverity($type, $options),
            message: $message,
            confidence: $confidence,
            subtype: $subtype,
            explanation: $explanation,
            suggestion: $suggestion,
            tag: $tag,
            scope: $scope,
            issue: $issue,
            owner: $owner,
            reason: $reason,
            raw: $raw,
        );
    }

    /**
     * @param list<CommentLine> $lines
     * @return list<CodeGroup>
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

            $groups[] = ['start_line' => $lines[$start]['line'], 'end_line' => $lines[$end]['line'], 'lines' => $end - $start + 1, 'start_index' => $start];
            $index = $end;
        }

        return $groups;
    }

    /**
     * @param Marker $parsed
     * @param list<string> $patterns
     */
    private function hasIssueReference(array $parsed, array $patterns): bool
    {
        $candidate = trim(($parsed['scope'] ?? '') . ' ' . $parsed['message']);

        return array_any($patterns, fn($pattern) => $this->safePregMatch($pattern, $candidate) === 1);
    }

    /** @param ScannerOptions $options */
    private function isAllowedReasonTag(string $tag, array $options): bool
    {
        if (in_array($tag, $options['allowedReasonTags'], true)) {
            return true;
        }

        if (!in_array($tag, $options['optionalReasonTags'], true)) {
            return false;
        }

        return !$options['strict'] || $options['allowOptionalReasonTagsInStrictMode'];
    }

    /** @param list<string> $labels */
    private function isExampleLabel(string $text, array $labels): bool
    {
        $normalizedText = strtolower(trim($text));
        if ($normalizedText === '') {
            return false;
        }

        return array_any($labels, fn($label) => $normalizedText === strtolower(trim((string) $label)));
    }

    private function isLikelyDocumentationLine(string $line): bool
    {
        $text = trim($line);
        if ($text === '' || str_starts_with($text, '@')) {
            return true;
        }

        return preg_match('/^[A-Z][^;{}]*[.?!]$/', $text) === 1;
    }

    private function isWeakReason(string $message, int $minReasonLength): bool
    {
        $reason = trim($message);
        if ($reason === '' || strlen($reason) < $minReasonLength) {
            return true;
        }

        $normalized = strtolower($reason);
        foreach (['todo', 'later', 'for now', 'old code', 'disabled', 'disabled for now', 'temporary', 'temp', 'testing', 'test', 'fix later', 'wip'] as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase . ' ')) {
                return true;
            }
        }

        return str_word_count($reason) < 3;
    }

    /**
     * @param ScannerOptions $options
     * @return list<string>
     */
    private function knownSuppressionRules(array $options): array
    {
        $rules = [
            'comment_marker',
            'commented_out_code_without_reason',
            'commented_out_code_without_valid_tag',
            'commented_out_code_without_valid_reason',
            'commented_out_code_with_weak_reason',
            'commented_out_code_with_valid_reason',
            'commented_out_code_block_too_large',
            'commented_out_code_requires_issue_reference',
            'commented_out_code_in_phpdoc_without_example_label',
            'invalid_suppression_rule',
            'expired_suppression_rule',
            'dead_suppression_rule',
            'phpdoc_signature_mismatch',
            'phpdoc_unknown_param',
            'phpdoc_missing_param',
            'phpdoc_invalid_tag_value',
        ];

        foreach ($options['customRules'] as $rule) {
            $ruleId = $rule['id'];

            if ($ruleId === '') {
                continue;
            }

            $rules[] = 'custom_rule_' . preg_replace('/[^A-Za-z0-9_]/', '_', strtolower($ruleId));
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param NormalizedComment $comment
     * @return list<CommentLine>
     */
    private function lastMeaningfulCommentLine(array $comment): array
    {
        $lines = $comment['lines'];
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            if (trim((string) $lines[$index]['text']) !== '') {
                return [$lines[$index]];
            }
        }

        return [];
    }

    private function loadDocCacheIfNeeded(): void
    {
        if ($this->docCacheLoaded) {
            return;
        }

        $this->docCacheLoaded = true;

        if (!$this->docCacheEnabled || $this->docCacheFile === '' || !is_file($this->docCacheFile) || !is_readable($this->docCacheFile)) {
            return;
        }

        $contents = file_get_contents($this->docCacheFile);

        if (!is_string($contents) || trim($contents) === '') {
            return;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return;
        }

        foreach ($decoded as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }

            $textParts = $entry['text_parts'] ?? null;
            if (!is_array($textParts) || !array_is_list($textParts)) {
                continue;
            }

            $this->persistentPhpDocCache[$key] = [
                'parsed' => ($entry['parsed'] ?? false) === true,
                'has_tags' => ($entry['has_tags'] ?? false) === true,
                'parse_error' => is_string($entry['parse_error'] ?? null) ? $entry['parse_error'] : null,
                'text_parts' => array_values(array_filter($textParts, is_string(...))),
            ];
        }
    }

    private function looksLikeCode(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^(TODO|FIXME|BUG|HACK|XXX|NOTE|OPTIMIZE|REFACTOR|DEPRECATED|SECURITY|REVIEW|QUESTION|WARNING)\b/i', $trimmed) === 1
            || preg_match('/^\s*@\w+/', $trimmed) === 1
            || preg_match('/^\s*(example|examples|usage|snippet|code sample)\s*:/i', $trimmed) === 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*:\s*[^;]+,?$/', $trimmed) === 1
            || $trimmed === '{'
            || $trimmed === '}') {
            return false;
        }

        return preg_match('/^\s*\$[A-Za-z_][A-Za-z0-9_]*\s*=|^\s*(if|else|elseif|foreach|for|while|switch|try|catch|finally)\b|^\s*(return|throw|new|class|interface|trait|enum|function|namespace|use)\b|->|::|;\s*$|<\?php\b|^\s*[A-Za-z_][A-Za-z0-9_]*\s*\([^)]*\)\s*;?\s*$/', $trimmed) === 1;
    }

    /** @return list<CommentLine> */
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

            $normalized[] = ['text' => trim($content), 'line' => $startLine + $index];
        }

        return $normalized;
    }

    /**
     * @param list<ExtractedComment> $comments
     * @param ScannerOptions $options
     * @return list<NormalizedComment>
     */
    private function normalizeComments(array $comments, array $options): array
    {
        $normalized = [];

        foreach ($comments as $comment) {
            $lines = $this->commentLines($comment);
            $parserFallback = false;
            $hasPhpDocTags = false;

            if ($comment['type'] === 'doc_comment' && $options['docMode'] !== 'heuristic') {
                $parsed = $this->parsePhpDoc($comment['raw']);
                $hasPhpDocTags = $parsed['has_tags'];
                $parserFallback = !$parsed['parsed'];
            }

            $normalized[] = [
                'type' => $comment['type'],
                'raw' => $comment['raw'],
                'line' => $comment['line'],
                'end_line' => $comment['end_line'],
                'lines' => $lines,
                'parser_fallback' => $parserFallback,
                'has_phpdoc_tags' => $hasPhpDocTags,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<CustomRule>
     */
    private function normalizeCustomRules(mixed $rules): array
    {
        if (!is_array($rules) || !array_is_list($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $id = is_string($rule['id'] ?? null) ? trim($rule['id']) : '';
            $pattern = is_string($rule['pattern'] ?? null) ? trim($rule['pattern']) : '';

            if ($id === '' || $pattern === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'pattern' => $pattern,
                'severity' => is_string($rule['severity'] ?? null) ? strtolower(trim($rule['severity'])) : 'warning',
                'message' => is_string($rule['message'] ?? null) && trim($rule['message']) !== ''
                    ? trim($rule['message'])
                    : sprintf('Matched custom comment rule "%s".', $id),
                'enabled' => ($rule['enabled'] ?? true) === true,
                'scope' => is_string($rule['scope'] ?? null) ? strtolower(trim($rule['scope'])) : 'all',
            ];
        }

        return $normalized;
    }

    private function normalizeLineComment(string $raw): string
    {
        $text = preg_replace('/^\s*(\/\/+|#)\s?/', '', $raw);

        return is_string($text) ? trim($text) : trim($raw);
    }

    /**
     * @param ScannerOptions $options
     * @return ScannerOptions
     */
    private function normalizeOptions(array $options): array
    {
        $docMode = strtolower(trim($options['docMode']));

        if (!in_array($docMode, ['heuristic', 'parser', 'hybrid'], true)) {
            $docMode = 'hybrid';
        }

        return [
            ...$options,
            'suppressionDirective' => trim($options['suppressionDirective']),
            'customRules' => $this->normalizeCustomRules($options['customRules']),
            'docMode' => $docMode,
            'docCacheFile' => trim($options['docCacheFile']) !== ''
                ? trim($options['docCacheFile'])
                : self::defaultDocCacheFile(),
        ];
    }

    /**
     * @param list<string> $tags
     * @return Marker|null
     */
    private function parseMarker(string $text, array $tags): ?array
    {
        if ($tags === []) {
            return null;
        }

        $patternKey = implode('|', array_map(strtoupper(...), $tags));
        if (!isset($this->markerPatternCache[$patternKey])) {
            $escaped = array_map(static fn(string $tag): string => preg_quote($tag, '/'), $tags);
            $this->markerPatternCache[$patternKey] = '/^\s*(' . implode('|', $escaped) . ')(?:\(([^)]*)\))?:?\s*(.*)$/i';
        }

        if (preg_match($this->markerPatternCache[$patternKey], $text, $matches) !== 1) {
            return null;
        }

        return [
            'tag' => strtoupper(trim($matches[1])),
            'scope' => isset($matches[2]) && trim($matches[2]) !== '' ? trim($matches[2]) : null,
            'message' => trim($matches[3] ?? ''),
        ];
    }

    /** @return PhpDocParse */
    private function parsePhpDoc(string $raw): array
    {
        $cacheKey = sha1($raw);
        if (isset($this->phpDocCache[$cacheKey])) {
            return $this->phpDocCache[$cacheKey];
        }

        $this->loadDocCacheIfNeeded();

        if ($this->docCacheEnabled && isset($this->persistentPhpDocCache[$cacheKey])) {
            $cached = $this->persistentPhpDocCache[$cacheKey];

            return $this->phpDocCache[$cacheKey] = [
                'parsed' => $cached['parsed'],
                'node' => null,
                'has_tags' => $cached['has_tags'],
                'parse_error' => $cached['parse_error'],
                'text_parts' => $cached['text_parts'],
            ];
        }

        try {
            $tokens = new TokenIterator($this->phpDocLexer()->tokenize($raw));
            $node = $this->phpDocParser()->parse($tokens);
            $hasTags = array_any($node->children, fn($child) => $child instanceof PhpDocTagNode);
            $textParts = $this->phpDocTextParts($node);

            if ($this->docCacheEnabled) {
                $this->persistentPhpDocCache[$cacheKey] = [
                    'parsed' => true,
                    'has_tags' => $hasTags,
                    'parse_error' => null,
                    'text_parts' => $textParts,
                ];
                $this->docCacheDirty = true;
            }

            return $this->phpDocCache[$cacheKey] = [
                'parsed' => true,
                'node' => $node,
                'has_tags' => $hasTags,
                'parse_error' => null,
                'text_parts' => $textParts,
            ];
        } catch (\Throwable $exception) {
            if ($this->docCacheEnabled) {
                $this->persistentPhpDocCache[$cacheKey] = [
                    'parsed' => false,
                    'has_tags' => false,
                    'parse_error' => $exception->getMessage(),
                    'text_parts' => [],
                ];
                $this->docCacheDirty = true;
            }

            return $this->phpDocCache[$cacheKey] = [
                'parsed' => false,
                'node' => null,
                'has_tags' => false,
                'parse_error' => $exception->getMessage(),
                'text_parts' => [],
            ];
        }
    }

    /**
     * @return array{valid:bool,rules:list<string>,until:?string,scope:?string,symbol:?string,error:?string}
     */
    private function parseSuppressionDirective(string $line, string $directive): array
    {
        $pattern = '/' . preg_quote($directive, '/') . '\s+([A-Za-z0-9_\-*.,]+)(?:\s+(.*))?$/';

        if (preg_match($pattern, $line, $matches) !== 1) {
            return [
                'valid' => false,
                'rules' => [],
                'until' => null,
                'scope' => null,
                'symbol' => null,
                'error' => 'Suppression directive does not match expected pattern.',
            ];
        }

        $rules = array_values(array_filter(array_map(trim(...), explode(',', $matches[1])), static fn(string $value): bool => $value !== ''));
        $until = null;
        $scope = null;
        $symbol = null;
        $tail = trim($matches[2] ?? '');

        if ($tail !== '') {
            foreach (preg_split('/\s+/', $tail) ?: [] as $token) {
                if ($token === '') {
                    continue;
                }

                if (!str_contains($token, '=')) {
                    return [
                        'valid' => false,
                        'rules' => [],
                        'until' => null,
                        'scope' => null,
                        'symbol' => null,
                        'error' => sprintf('Unknown suppression token "%s".', $token),
                    ];
                }

                [$key, $value] = array_pad(explode('=', $token, 2), 2, '');
                $key = strtolower(trim($key));
                $value = trim($value);

                if ($key === 'until') {
                    $until = $value;

                    continue;
                }

                if ($key === 'scope') {
                    $scope = strtolower($value);

                    continue;
                }

                if ($key === 'symbol') {
                    $symbol = $value;

                    continue;
                }

                return [
                    'valid' => false,
                    'rules' => [],
                    'until' => null,
                    'scope' => null,
                    'symbol' => null,
                    'error' => sprintf('Unknown suppression option "%s".', $key),
                ];
            }
        }

        if ($scope !== null && !in_array($scope, ['line', 'symbol'], true)) {
            return [
                'valid' => false,
                'rules' => [],
                'until' => null,
                'scope' => null,
                'symbol' => null,
                'error' => sprintf('Unsupported suppression scope "%s".', $scope),
            ];
        }

        return [
            'valid' => true,
            'rules' => $rules,
            'until' => $until,
            'scope' => $scope,
            'symbol' => $symbol,
            'error' => null,
        ];
    }

    private function persistDocCache(): void
    {
        if (!$this->docCacheEnabled || !$this->docCacheDirty || $this->docCacheFile === '') {
            return;
        }

        $encoded = json_encode($this->persistentPhpDocCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            return;
        }

        try {
            AtomicFileWriter::write($this->docCacheFile, $encoded . PHP_EOL);
        } catch (\Throwable) {
            // Ignore cache write failures; scanner results remain valid.
        }
    }

    /**
     * @param list<CommentLine> $lines
     * @param list<string> $labels
     */
    private function phpDocHasExampleLabel(array $lines, array $labels): bool
    {
        return array_any($lines, fn($line) => $this->isExampleLabel($line['text'], $labels));
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

    /**
     * @param list<string> $textParts
     * @return list<CommentLine>
     */
    private function phpDocTextLines(?PhpDocNode $node, int $startLine, array $textParts = []): array
    {
        $lines = [];
        $line = $startLine;

        if ($node instanceof PhpDocNode) {
            foreach ($this->phpDocTextParts($node) as $part) {
                foreach (preg_split('/\R/', $part) ?: [$part] as $textLine) {
                    $trimmed = trim($textLine);
                    if ($trimmed !== '') {
                        $lines[] = ['text' => $trimmed, 'line' => $line];
                    }
                    $line++;
                }
            }
        } else {
            foreach ($textParts as $part) {
                foreach (preg_split('/\R/', $part) ?: [$part] as $textLine) {
                    $trimmed = trim($textLine);
                    if ($trimmed !== '') {
                        $lines[] = ['text' => $trimmed, 'line' => $line];
                    }
                    $line++;
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function phpDocTextParts(PhpDocNode $node): array
    {
        $parts = [];

        foreach ($node->children as $child) {
            if ($child instanceof PhpDocTextNode) {
                $parts[] = $child->text;
            }
        }

        return $parts;
    }

    /** @param ScannerOptions $options */
    private function prepareDocCache(array $options): void
    {
        $this->docCacheEnabled = $options['docCacheEnabled'] === true;
        $this->docCacheFile = $options['docCacheFile'];
        $this->docCacheDirty = false;
        $this->docCacheLoaded = false;
        $this->persistentPhpDocCache = [];
    }

    /**
     * @param list<CommentLine> $lines
     * @return CommentLine|null
     */
    private function reasonBeforeIndex(array $lines, int $index, bool $allowBlankLine): ?array
    {
        if ($index <= 0) {
            return null;
        }

        if (!$allowBlankLine) {
            return trim((string) $lines[$index - 1]['text']) !== '' ? $lines[$index - 1] : null;
        }

        for ($scan = $index - 1; $scan >= 0; $scan--) {
            if (trim((string) $lines[$scan]['text']) !== '') {
                return $lines[$scan];
            }
        }

        return null;
    }

    private function relativePath(string $path): string
    {
        return ProjectPath::relative($path);
    }

    private function safePregMatch(string $pattern, string $subject): int|false
    {
        set_error_handler(static fn(): bool => true);

        try {
            return preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param list<NormalizedComment> $comments
     * @param ScannerOptions $options
     * @return list<CommentFinding>
     */
    private function scanBlockCommentedCode(string $file, array $comments, array $options): array
    {
        $findings = [];
        $count = count($comments);

        for ($i = 0; $i < $count; $i++) {
            $comment = $comments[$i];

            if (!in_array($comment['type'], ['block_comment', 'doc_comment'], true)) {
                continue;
            }

            $source = $comment['type'] === 'doc_comment' ? 'doc' : 'block';
            $analysis = $this->effectiveCommentAnalysis($comment, $options);

            if ($analysis['skip']) {
                continue;
            }

            $groups = $this->codeGroups($analysis['lines']);
            if ($groups === []) {
                continue;
            }

            $hasExampleLabel = $source === 'doc'
                && $options['allowPhpdocExamples']
                && $this->phpDocHasExampleLabel($comment['lines'], $options['phpdocExampleLabels']);

            foreach ($groups as $group) {
                $reasonCandidate = $this->reasonBeforeIndex($analysis['lines'], $group['start_index'], $options['allowBlankLineBetweenReasonAndCodeInBlock']);

                if ($reasonCandidate === null && $options['allowReasonBeforeBlockComment']) {
                    $previous = $comments[$i - 1] ?? null;
                    if (is_array($previous) && ($previous['end_line'] + 1) === $comment['line']) {
                        $reasonCandidate = $this->lastMeaningfulCommentLine($previous)[0] ?? null;
                    }
                }

                if ($source === 'doc' && is_array($reasonCandidate) && $this->isLikelyDocumentationLine($reasonCandidate['text'])) {
                    $reasonCandidate = null;
                }

                if ($source === 'doc'
                    && $hasExampleLabel
                    && ($reasonCandidate === null || $this->isExampleLabel($reasonCandidate['text'], $options['phpdocExampleLabels']))) {
                    continue;
                }

                $reason = $this->detectReasonStatus(
                    $file,
                    $group['start_line'],
                    $group['end_line'],
                    $group['lines'],
                    $reasonCandidate,
                    $source,
                    $hasExampleLabel,
                    $analysis['parser_fallback'],
                    $options,
                );

                if (!($hasExampleLabel && $reason->type === 'commented_out_code_in_phpdoc_without_example_label')) {
                    $findings[] = $reason;
                }

                if ($group['lines'] > $options['maxAllowedBlockLines']) {
                    $findings[] = $this->finding(
                        $file,
                        $group['start_line'],
                        $group['end_line'],
                        'commented_out_code_block_too_large',
                        sprintf('Commented-out code block has %d lines; maximum allowed is %d.', $group['lines'], $options['maxAllowedBlockLines']),
                        $options,
                        confidence: 'high',
                        subtype: $source === 'doc' ? 'doc_snippet_too_large' : 'snippet_too_large',
                        explanation: $options['explain'] ? sprintf('Block size exceeded max_allowed_block_lines=%d.', $options['maxAllowedBlockLines']) : null,
                        suggestion: 'Delete stale commented-out code or restore it into live code/tests.',
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<NormalizedComment> $comments
     * @param ScannerOptions $options
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
     * @param list<NormalizedComment> $comments
     * @param ScannerOptions $options
     * @return list<CommentFinding>
     */
    private function scanCustomRules(string $file, array $comments, array $options): array
    {
        if ($options['customRules'] === []) {
            return [];
        }

        $findings = [];

        foreach ($options['customRules'] as $rule) {
            if (!$rule['enabled']) {
                continue;
            }

            $ruleType = 'custom_rule_' . preg_replace('/[^A-Za-z0-9_]/', '_', strtolower((string) $rule['id']));

            foreach ($comments as $comment) {
                $scope = $rule['scope'];
                $commentScope = $comment['type'] === 'doc_comment'
                    ? 'doc'
                    : ($comment['type'] === 'line_comment' ? 'line' : 'block');

                if ($scope !== 'all' && $scope !== $commentScope) {
                    continue;
                }

                foreach ($comment['lines'] as $line) {
                    if ($this->safePregMatch((string) $rule['pattern'], (string) $line['text']) !== 1) {
                        continue;
                    }

                    $findings[] = new CommentFinding(
                        file: $this->relativePath($file),
                        line: $line['line'],
                        endLine: $line['line'],
                        type: $ruleType,
                        severity: $rule['severity'],
                        message: $rule['message'],
                        confidence: 'high',
                        subtype: 'custom_rule_match',
                        explanation: $options['explain'] ? sprintf('Matched custom rule id "%s" with pattern %s.', $rule['id'], $rule['pattern']) : null,
                        raw: $line['text'],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<NormalizedComment> $comments
     * @param ScannerOptions $options
     * @return list<CommentFinding>
     */
    private function scanLineCommentedCode(string $file, array $comments, array $options): array
    {
        $entries = [];
        foreach ($comments as $comment) {
            if ($comment['type'] !== 'line_comment') {
                continue;
            }

            foreach ($comment['lines'] as $line) {
                $entries[] = ['line' => $line['line'], 'text' => $line['text']];
            }
        }

        usort($entries, static fn(array $a, array $b): int => $a['line'] <=> $b['line']);
        $entryByLine = [];
        foreach ($entries as $entry) {
            $entryByLine[$entry['line']] = $entry;
        }

        $findings = [];

        foreach ($this->groupAdjacentCodeLines($entries) as $group) {
            $reasonCandidate = null;
            $reasonLine = $group['start_line'] - 1;

            if (!$options['allowBlankLineBetweenReasonAndCode']) {
                if (isset($entryByLine[$reasonLine]) && trim($entryByLine[$reasonLine]['text']) !== '') {
                    $reasonCandidate = ['text' => $entryByLine[$reasonLine]['text'], 'line' => $reasonLine];
                }
            } else {
                for ($scan = $reasonLine; $scan >= 1; $scan--) {
                    if (!isset($entryByLine[$scan])) {
                        continue;
                    }

                    if (trim($entryByLine[$scan]['text']) !== '') {
                        $reasonCandidate = ['text' => $entryByLine[$scan]['text'], 'line' => $scan];
                    }

                    break;
                }
            }

            $reason = $this->detectReasonStatus(
                $file,
                $group['start_line'],
                $group['end_line'],
                $group['lines'],
                $reasonCandidate,
                'line',
                false,
                false,
                $options,
            );

            $findings[] = $reason;

            if ($group['lines'] > $options['maxAllowedBlockLines']) {
                $findings[] = $this->finding(
                    $file,
                    $group['start_line'],
                    $group['end_line'],
                    'commented_out_code_block_too_large',
                    sprintf('Commented-out code block has %d lines; maximum allowed is %d.', $group['lines'], $options['maxAllowedBlockLines']),
                    $options,
                    confidence: 'high',
                    subtype: 'snippet_too_large',
                    explanation: $options['explain'] ? sprintf('Block size exceeded max_allowed_block_lines=%d.', $options['maxAllowedBlockLines']) : null,
                    suggestion: 'Delete stale commented-out code or restore it into live code/tests.',
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<NormalizedComment> $comments
     * @param ScannerOptions $options
     * @return list<CommentFinding>
     */
    private function scanMarkers(string $file, array $comments, array $options): array
    {
        if (!$options['scanMarkers']) {
            return [];
        }

        $findings = [];

        foreach ($comments as $comment) {
            foreach ($comment['lines'] as $line) {
                $parsed = $this->parseMarker($line['text'], $options['markerTags']);

                if ($parsed === null) {
                    continue;
                }

                $findings[] = new CommentFinding(
                    file: $this->relativePath($file),
                    line: $line['line'],
                    endLine: $line['line'],
                    type: 'comment_marker',
                    severity: $options['markerSeverity'][$parsed['tag']] ?? 'info',
                    message: sprintf('Detected %s marker.', $parsed['tag']),
                    confidence: 'high',
                    subtype: 'marker_tag',
                    explanation: $options['explain'] ? sprintf('Matched marker pattern for tag %s.', $parsed['tag']) : null,
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
     * @param list<array{id:string,aliases:list<string>,start_line:int,end_line:int}> $symbols
     * @return array{start_line:int,end_line:int}|null
     */
    private function suppressionRange(int $line, ?string $scope, ?string $symbol, array $symbols): ?array
    {
        if ($scope !== 'symbol' && ($symbol === null || trim($symbol) === '')) {
            return ['start_line' => $line, 'end_line' => $line + 1];
        }

        if (is_string($symbol) && trim($symbol) !== '') {
            $target = strtolower(trim($symbol));

            foreach ($symbols as $entry) {
                foreach ($entry['aliases'] as $alias) {
                    if ($target === strtolower($alias)) {
                        return [
                            'start_line' => $entry['start_line'],
                            'end_line' => $entry['end_line'],
                        ];
                    }
                }
            }

            return null;
        }

        $selected = null;
        $selectedSpan = null;

        foreach ($symbols as $entry) {
            if ($line < $entry['start_line'] || $line > $entry['end_line']) {
                continue;
            }

            $span = $entry['end_line'] - $entry['start_line'];
            if ($selected === null || $span < $selectedSpan) {
                $selected = $entry;
                $selectedSpan = $span;
            }
        }

        if ($selected === null) {
            return null;
        }

        return [
            'start_line' => $selected['start_line'],
            'end_line' => $selected['end_line'],
        ];
    }

    /** @param ScannerOptions $options */
    private function typeSeverity(string $type, array $options): string
    {
        if ($options['strict'] && isset($options['strictSeverity'][$type])) {
            return $options['strictSeverity'][$type];
        }

        return $options['typeSeverity'][$type] ?? 'info';
    }
}

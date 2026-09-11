<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Detection;

use Infocyph\PHPProbe\Util\ProjectPath;

final class DuplicateDetectionEngine
{
    public const int CACHE_VERSION = 8;

    private const int ROLLING_BASE = 257;

    private const int ROLLING_MODULUS = 1_000_000_007;

    /**
     * @param list<string> $files
     * @param array{mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,maxNearMissComparisons:int} $options
     * @return array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>}
     */
    public function analyze(array $files, array $options): array
    {
        $includeAst = $options['mode'] === 'audit' || $options['nearMiss'];
        $index = new DuplicateCodeIndex()->build($files, $options, $includeAst);
        $reducer = new DuplicateCloneReducer();
        $clones = [
            ...$this->tokenClones($index['streams'], $index['blocks'], $options, $reducer),
            ...$this->statementClones($index['blocks'], $options, $reducer),
            ...$this->nearMissClones($index['blocks'], $options, $reducer),
        ];

        $clones = $reducer->rank($reducer->pruneContained($reducer->group($clones)));
        $duplicatedLines = $reducer->uniqueDuplicatedLines($clones);

        return [
            'files' => count($files),
            'total_lines' => $index['total_lines'],
            'duplicated_lines' => $duplicatedLines,
            'duplicate_percentage' => $index['total_lines'] > 0 ? round(($duplicatedLines / $index['total_lines']) * 100, 2) : 0.0,
            'known_clones' => 0,
            'new_clones' => count($clones),
            'clones' => $clones,
        ];
    }

    /**
     * @param array<string, array{statements:int,occurrences:array<string, array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $cloneMap
     * @param array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>} $block
     * @param array{minLines:int,minStatements:int} $options
     */
    private function addStatementClone(array &$cloneMap, string $hash, array $block, array $options): void
    {
        $occurrence = $this->blockOccurrence($block);

        if ($occurrence['lines'] < $options['minLines'] || count($block['statement_hashes']) < $options['minStatements']) {
            return;
        }

        $cloneMap[$hash] ??= ['statements' => count($block['statement_hashes']), 'occurrences' => []];
        $cloneMap[$hash]['occurrences'][$this->occurrenceKey($occurrence)] = $occurrence;
    }

    /**
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @return array<string, array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>
     */
    private function blockIdIndex(array $blocks): array
    {
        $index = [];

        foreach ($blocks as $fileBlocks) {
            foreach ($fileBlocks as $block) {
                $index[$block['id']] = $block;
            }
        }

        return $index;
    }

    /**
     * @param array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>} $block
     * @return array{file:string,start_line:int,end_line:int,lines:int,context:string}
     */
    private function blockOccurrence(array $block): array
    {
        return [
            'file' => $this->relativePath($block['file']),
            'start_line' => $block['start_line'],
            'end_line' => $block['end_line'],
            'lines' => $block['end_line'] - $block['start_line'] + 1,
            'context' => $block['type'],
        ];
    }

    /**
     * @param array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>} $left
     * @param array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>} $right
     */
    private function canReachNearMissSimilarity(array $left, array $right, float $minSimilarity): bool
    {
        $statementBound = $this->similarityUpperBound(count($left['statement_hashes']), count($right['statement_hashes']));
        $shapeBound = $this->similarityUpperBound(count($left['shape']), count($right['shape']));

        return round(($statementBound * 0.72) + ($shapeBound * 0.28), 4) >= $minSimilarity;
    }

    /**
     * @param list<array{value:string,semantic:string,line:int}> $tokens
     */
    private function cloneSignature(array $tokens, int $start, int $tokenCount): string
    {
        return $this->tokenValueHash($tokens, $start, $tokenCount);
    }

    /**
     * @param array<string, array{tokens:int,occurrences:array<string, array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $cloneMap
     * @param array<string, list<array{value:string,semantic:string,line:int}>> $streams
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param array{file:string,index:int} $left
     * @param array{file:string,index:int} $right
     * @param array{minLines:int,minTokens:int} $options
     */
    private function collectTokenCloneCandidate(array &$cloneMap, array $streams, array $blocks, array $left, array $right, array $options): void
    {
        if ($this->isOverlapping($left, $right, $options['minTokens']) || !$this->isLeftmostClone($streams, $left, $right)) {
            return;
        }

        $tokenCount = $this->tokenCloneLength($streams, $blocks, $left, $right);

        if ($tokenCount < $options['minTokens']) {
            return;
        }

        if ($this->isOverlapping($left, $right, $tokenCount)) {
            return;
        }

        if (!$this->hasCompatibleSemantics($streams, $blocks, $left, $right, $tokenCount)) {
            return;
        }

        $leftOccurrence = $this->tokenOccurrence($streams[$left['file']], $blocks[$left['file']] ?? [], $left['file'], $left['index'], $tokenCount);
        $rightOccurrence = $this->tokenOccurrence($streams[$right['file']], $blocks[$right['file']] ?? [], $right['file'], $right['index'], $tokenCount);

        if (min($leftOccurrence['lines'], $rightOccurrence['lines']) < $options['minLines']) {
            return;
        }

        $signature = $this->cloneSignature($streams[$left['file']], $left['index'], $tokenCount);
        $cloneMap[$signature] ??= ['tokens' => $tokenCount, 'occurrences' => []];
        $cloneMap[$signature]['occurrences'][$this->occurrenceKey($leftOccurrence)] = $leftOccurrence;
        $cloneMap[$signature]['occurrences'][$this->occurrenceKey($rightOccurrence)] = $rightOccurrence;
    }

    /**
     * @param array<string, array{tokens:int,occurrences:array<string, array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $cloneMap
     * @param array<string, list<array{value:string,semantic:string,line:int}>> $streams
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param list<array{file:string,index:int}> $occurrences
     * @param array{minLines:int,minTokens:int} $options
     */
    private function collectTokenWindowClones(array &$cloneMap, array $streams, array $blocks, array $occurrences, array $options): void
    {
        $occurrenceCount = count($occurrences);

        for ($leftIndex = 0; $leftIndex < $occurrenceCount - 1; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $occurrenceCount; $rightIndex++) {
                $this->collectTokenCloneCandidate($cloneMap, $streams, $blocks, $occurrences[$leftIndex], $occurrences[$rightIndex], $options);
            }
        }
    }

    private function coverageRatio(int $start, int $end, int $blockStart, int $blockEnd): float
    {
        return min($end - $start + 1, $blockEnd - $blockStart + 1) / max(1, max($end - $start + 1, $blockEnd - $blockStart + 1));
    }

    /**
     * @param list<array{value:string,semantic:string,line:int}> $left
     * @param list<array{value:string,semantic:string,line:int}> $right
     */
    private function extendLength(array $left, int $leftStart, array $right, int $rightStart): int
    {
        $length = 0;

        while (isset($left[$leftStart + $length], $right[$rightStart + $length]) && $left[$leftStart + $length]['value'] === $right[$rightStart + $length]['value']) {
            $length++;
        }

        return $length;
    }

    /**
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param array{minLines:int,minStatements:int} $options
     * @return list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>
     */
    private function flatBlocks(array $blocks, array $options): array
    {
        $flat = [];

        foreach ($blocks as $fileBlocks) {
            foreach ($fileBlocks as $block) {
                if (($block['end_line'] - $block['start_line'] + 1) >= $options['minLines'] && count($block['statement_hashes']) >= $options['minStatements']) {
                    $flat[] = $block;
                }
            }
        }

        return $flat;
    }

    /**
     * @param list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}> $blocks
     */
    private function functionScopeLength(array $blocks, int $tokenStart, int $maxLength): int
    {
        if ($maxLength <= 0) {
            return 0;
        }

        $scope = $this->smallestContainingFunctionBlock($blocks, $tokenStart);

        return $scope === null
            ? $maxLength
            : min($maxLength, $scope['token_end'] - $tokenStart + 1);
    }

    /**
     * @param array<string, list<array{value:string,semantic:string,line:int}>> $streams
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param array{file:string,index:int} $left
     * @param array{file:string,index:int} $right
     */
    private function hasCompatibleSemantics(
        array $streams,
        array $blocks,
        array $left,
        array $right,
        int $tokenCount,
    ): bool {
        if ($this->isSameFunctionScope($blocks, $left, $right)) {
            return true;
        }

        for ($offset = 0; $offset < $tokenCount; $offset++) {
            $leftToken = $streams[$left['file']][$left['index'] + $offset];
            $rightToken = $streams[$right['file']][$right['index'] + $offset];

            if ($leftToken['semantic'] !== $rightToken['semantic']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, list<array{value:string,semantic:string,line:int}>> $streams
     * @param array{file:string,index:int} $left
     * @param array{file:string,index:int} $right
     */
    private function isLeftmostClone(array $streams, array $left, array $right): bool
    {
        if ($left['index'] === 0 || $right['index'] === 0) {
            return true;
        }

        return ($streams[$left['file']][$left['index'] - 1]['value'] ?? null) !== ($streams[$right['file']][$right['index'] - 1]['value'] ?? null);
    }

    /**
     * @param array{file:string,index:int} $left
     * @param array{file:string,index:int} $right
     */
    private function isOverlapping(array $left, array $right, int $minTokens): bool
    {
        return $left['file'] === $right['file'] && abs($left['index'] - $right['index']) < $minTokens;
    }

    /**
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param array{file:string,index:int} $left
     * @param array{file:string,index:int} $right
     */
    private function isSameFunctionScope(array $blocks, array $left, array $right): bool
    {
        if ($left['file'] !== $right['file']) {
            return false;
        }

        $fileBlocks = $blocks[$left['file']] ?? [];
        $leftScope = $this->smallestContainingFunctionBlock($fileBlocks, $left['index']);
        $rightScope = $this->smallestContainingFunctionBlock($fileBlocks, $right['index']);

        return $leftScope !== null
            && $rightScope !== null
            && $leftScope['id'] === $rightScope['id'];
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function lcsLength(array $left, array $right): int
    {
        $previous = array_fill(0, count($right) + 1, 0);

        foreach ($left as $leftValue) {
            $current = [0];

            foreach ($right as $rightIndex => $rightValue) {
                $current[] = $leftValue === $rightValue ? $previous[$rightIndex] + 1 : max($previous[$rightIndex + 1], $current[$rightIndex]);
            }

            $previous = $current;
        }

        return $previous[count($right)];
    }

    /**
     * @param array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>} $left
     * @param array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>} $right
     * @param array{minSimilarity:float} $options
     * @return array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}|null
     */
    private function nearMissClone(array $left, array $right, array $options, DuplicateCloneReducer $reducer): ?array
    {
        if ($left['id'] === $right['id'] || $left['type'] !== $right['type']) {
            return null;
        }

        $similarity = round(($this->sequenceSimilarity($left['statement_hashes'], $right['statement_hashes']) * 0.72) + ($this->sequenceSimilarity($left['shape'], $right['shape']) * 0.28), 4);

        return $similarity >= $options['minSimilarity']
            ? $reducer->makeClone(
                'near_miss',
                [$this->blockOccurrence($left), $this->blockOccurrence($right)],
                0,
                min(count($left['statement_hashes']), count($right['statement_hashes'])),
                $similarity,
                $this->nearMissIdentity($left, $right),
            )
            : null;
    }

    /**
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param array{nearMiss:bool,minLines:int,minStatements:int,minSimilarity:float,maxNearMissComparisons:int} $options
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    private function nearMissClones(array $blocks, array $options, DuplicateCloneReducer $reducer): array
    {
        if (!$options['nearMiss']) {
            return [];
        }

        return $this->nearMissPairs($this->flatBlocks($blocks, $options), $options, $reducer);
    }

    /**
     * @param array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>} $left
     * @param array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>} $right
     */
    private function nearMissIdentity(array $left, array $right): string
    {
        $identities = [
            hash('sha256', implode("\0", [...$left['statement_hashes'], ...$left['shape']])),
            hash('sha256', implode("\0", [...$right['statement_hashes'], ...$right['shape']])),
        ];
        sort($identities, SORT_STRING);

        return implode('|', $identities);
    }

    /**
     * @param list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}> $blocks
     * @param array{minSimilarity:float,maxNearMissComparisons:int} $options
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    private function nearMissPairs(array $blocks, array $options, DuplicateCloneReducer $reducer): array
    {
        $clones = [];
        $grouped = [];
        $comparisons = 0;

        foreach ($blocks as $block) {
            $grouped[$block['type']][] = $block;
        }

        foreach ($grouped as $typedBlocks) {
            $blockCount = count($typedBlocks);

            for ($left = 0; $left < $blockCount - 1; $left++) {
                for ($right = $left + 1; $right < $blockCount; $right++) {
                    if (!$this->canReachNearMissSimilarity($typedBlocks[$left], $typedBlocks[$right], $options['minSimilarity'])) {
                        continue;
                    }

                    $comparisons++;

                    if ($comparisons > $options['maxNearMissComparisons']) {
                        throw new \RuntimeException(sprintf(
                            'Near-miss comparison limit exceeded (%d). Narrow the scan, raise min_similarity, or increase max_near_miss_comparisons.',
                            $options['maxNearMissComparisons'],
                        ));
                    }

                    $clone = $this->nearMissClone($typedBlocks[$left], $typedBlocks[$right], $options, $reducer);

                    if ($clone !== null) {
                        $clones[] = $clone;
                    }
                }
            }
        }

        return $clones;
    }

    /**
     * @param array{file:string,start_line:int,end_line:int,lines:int,context:string} $occurrence
     */
    private function occurrenceKey(array $occurrence): string
    {
        return $occurrence['file'] . ':' . $occurrence['start_line'] . '-' . $occurrence['end_line'];
    }

    private function relativePath(string $path): string
    {
        return ProjectPath::relative($path);
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function sequenceSimilarity(array $left, array $right): float
    {
        return $left === [] || $right === [] ? 0.0 : $this->lcsLength($left, $right) / max(count($left), count($right));
    }

    private function similarityUpperBound(int $leftLength, int $rightLength): float
    {
        return min($leftLength, $rightLength) / max(1, max($leftLength, $rightLength));
    }

    /**
     * @param list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}> $blocks
     * @return array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}|null
     */
    private function smallestContainingBlock(array $blocks, int $tokenStart, int $tokenEnd): ?array
    {
        $best = null;

        foreach ($blocks as $block) {
            if ($block['token_start'] <= $tokenStart && $block['token_end'] >= $tokenEnd) {
                $best = $best === null || ($block['token_end'] - $block['token_start']) < ($best['token_end'] - $best['token_start']) ? $block : $best;
            }
        }

        return $best;
    }

    /**
     * @param list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}> $blocks
     * @return array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}|null
     */
    private function smallestContainingFunctionBlock(array $blocks, int $tokenIndex): ?array
    {
        $best = null;

        foreach ($blocks as $block) {
            if (
                $block['type'] !== 'function'
                || $block['token_start'] > $tokenIndex
                || $block['token_end'] < $tokenIndex
            ) {
                continue;
            }

            if (
                $best === null
                || ($block['token_end'] - $block['token_start'])
                    < ($best['token_end'] - $best['token_start'])
            ) {
                $best = $block;
            }
        }

        return $best;
    }

    /**
     * @param array<string, array{statements:int,occurrences:array<string, array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $cloneMap
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    private function statementCloneMapToClones(array $cloneMap, DuplicateCloneReducer $reducer): array
    {
        $clones = [];

        foreach ($cloneMap as $hash => $clone) {
            if (count($clone['occurrences']) >= 2) {
                $clones[] = $reducer->makeClone('statements', array_values($clone['occurrences']), 0, $clone['statements'], 1.0, (string) $hash);
            }
        }

        return $clones;
    }

    /**
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param array{mode:string,minLines:int,minStatements:int} $options
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    private function statementClones(array $blocks, array $options, DuplicateCloneReducer $reducer): array
    {
        if ($options['mode'] !== 'audit') {
            return [];
        }

        $blockIndex = $this->blockIdIndex($blocks);
        $cloneMap = [];

        foreach ($this->statementWindows($blocks, $options['minStatements']) as $occurrences) {
            foreach ($occurrences as $occurrence) {
                $block = $blockIndex[$occurrence['block']] ?? null;

                if ($block !== null) {
                    $this->addStatementClone($cloneMap, $occurrence['hash'], $block, $options);
                }
            }
        }

        return $this->statementCloneMapToClones($cloneMap, $reducer);
    }

    /**
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @return array<string, list<array{block:string,hash:string}>>
     */
    private function statementWindows(array $blocks, int $minStatements): array
    {
        $firstOccurrences = [];
        $duplicateWindows = [];

        foreach ($blocks as $fileBlocks) {
            foreach ($fileBlocks as $block) {
                $statementWindowLimit = count($block['statement_hashes']) - $minStatements;

                for ($index = 0; $index <= $statementWindowLimit; $index++) {
                    $hash = hash('sha256', implode("\0", array_slice($block['statement_hashes'], $index, $minStatements)));
                    $occurrence = ['block' => $block['id'], 'hash' => $hash];

                    if (!isset($firstOccurrences[$hash])) {
                        $firstOccurrences[$hash] = $occurrence;

                        continue;
                    }

                    $duplicateWindows[$hash] ??= [$firstOccurrences[$hash]];

                    $duplicateWindows[$hash][] = $occurrence;
                }
            }
        }

        return $duplicateWindows;
    }

    /**
     * @param array<string, list<array{value:string,semantic:string,line:int}>> $streams
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param array{file:string,index:int} $left
     * @param array{file:string,index:int} $right
     */
    private function tokenCloneLength(array $streams, array $blocks, array $left, array $right): int
    {
        $length = $this->extendLength(
            $streams[$left['file']],
            $left['index'],
            $streams[$right['file']],
            $right['index'],
        );

        return min(
            $length,
            $this->functionScopeLength($blocks[$left['file']] ?? [], $left['index'], $length),
            $this->functionScopeLength($blocks[$right['file']] ?? [], $right['index'], $length),
        );
    }

    /**
     * @param array<string, array{tokens:int,occurrences:array<string, array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $cloneMap
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    private function tokenCloneMapToClones(array $cloneMap, DuplicateCloneReducer $reducer): array
    {
        $clones = [];

        foreach ($cloneMap as $signature => $clone) {
            if (count($clone['occurrences']) >= 2) {
                $clones[] = $reducer->makeClone('tokens', array_values($clone['occurrences']), $clone['tokens'], 0, 1.0, (string) $signature);
            }
        }

        return $clones;
    }

    /**
     * @param array<string, list<array{value:string,semantic:string,line:int}>> $streams
     * @param array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>> $blocks
     * @param array{minLines:int,minTokens:int} $options
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    private function tokenClones(array $streams, array $blocks, array $options, DuplicateCloneReducer $reducer): array
    {
        $cloneMap = [];

        foreach ($this->tokenWindows($streams, $options['minTokens']) as $occurrences) {
            $this->collectTokenWindowClones($cloneMap, $streams, $blocks, $occurrences, $options);
        }

        return $this->tokenCloneMapToClones($cloneMap, $reducer);
    }

    /**
     * @param list<array{value:string,semantic:string,line:int}> $tokens
     * @param list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}> $blocks
     * @return array{file:string,start_line:int,end_line:int,lines:int,context:string}
     */
    private function tokenOccurrence(array $tokens, array $blocks, string $file, int $start, int $tokenCount): array
    {
        $startLine = $tokens[$start]['line'];
        $endLine = $tokens[$start + $tokenCount - 1]['line'];
        $context = $this->smallestContainingBlock($blocks, $start, $start + $tokenCount - 1);

        if ($context !== null && $this->coverageRatio($start, $start + $tokenCount - 1, $context['token_start'], $context['token_end']) >= 0.70) {
            $startLine = $context['start_line'];
            $endLine = $context['end_line'];
        }

        return [
            'file' => $this->relativePath($file),
            'start_line' => $startLine,
            'end_line' => $endLine,
            'lines' => $endLine - $startLine + 1,
            'context' => $context['type'] ?? '',
        ];
    }

    /**
     * @param list<array{value:string,semantic:string,line:int}> $tokens
     */
    private function tokenValueHash(array $tokens, int $start, int $length): string
    {
        $values = [];

        for ($offset = 0; $offset < $length; $offset++) {
            $values[] = $tokens[$start + $offset]['value'];
        }

        return hash('sha256', implode("\0", $values));
    }

    private function tokenValueNumber(string $value): int
    {
        return (crc32($value) % (self::ROLLING_MODULUS - 1)) + 1;
    }

    /**
     * @param array<string, list<array{value:string,semantic:string,line:int}>> $streams
     * @return array<string, list<array{file:string,index:int}>>
     */
    private function tokenWindows(array $streams, int $minTokens): array
    {
        $firstOccurrences = [];
        $duplicateWindows = [];

        foreach ($streams as $file => $tokens) {
            $tokenWindowLimit = count($tokens) - $minTokens;

            if ($tokenWindowLimit < 0) {
                continue;
            }

            $power = 1;

            for ($offset = 1; $offset < $minTokens; $offset++) {
                $power = ($power * self::ROLLING_BASE) % self::ROLLING_MODULUS;
            }

            $hash = 0;

            for ($offset = 0; $offset < $minTokens; $offset++) {
                $hash = (($hash * self::ROLLING_BASE) + $this->tokenValueNumber($tokens[$offset]['value'])) % self::ROLLING_MODULUS;
            }

            for ($index = 0; $index <= $tokenWindowLimit; $index++) {
                if ($index > 0) {
                    $removed = ($this->tokenValueNumber($tokens[$index - 1]['value']) * $power) % self::ROLLING_MODULUS;
                    $hash = ($hash - $removed + self::ROLLING_MODULUS) % self::ROLLING_MODULUS;
                    $hash = (($hash * self::ROLLING_BASE) + $this->tokenValueNumber($tokens[$index + $minTokens - 1]['value'])) % self::ROLLING_MODULUS;
                }

                // Numeric-string keys are coerced to integers by PHP arrays.
                $key = 'h' . $hash;
                $occurrence = ['file' => $file, 'index' => $index];

                if (!isset($firstOccurrences[$key])) {
                    $firstOccurrences[$key] = $occurrence;

                    continue;
                }

                $duplicateWindows[$key] ??= [$firstOccurrences[$key]];

                $duplicateWindows[$key][] = $occurrence;
            }
        }

        return $duplicateWindows;
    }
}

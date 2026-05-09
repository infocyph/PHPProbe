<?php

declare(strict_types=1);

use Infocyph\PHPProbe\Comment\CommentScanner;

it('keeps hybrid comment scan runtime within regression budget on synthetic docs', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpprobe-comment-perf-'.uniqid('', true);
    $file = $root.DIRECTORY_SEPARATOR.'Synthetic.php';

    mkdir($root, 0755, true);

    $doc = <<<'PHP'
/**
 * Creates an instance from container.
 *
 * @param string $id Service id.
 * @return object
 */
PHP;

    $contents = "<?php\n\nfinal class Synthetic\n{\n";

    for ($i = 0; $i < 400; $i++) {
        $contents .= $doc . "\n";
        $contents .= "// TODO(#{$i}): restore after migration\n";
        $contents .= "// \$legacy{$i} = \$service->run(\$input);\n";
    }

    $contents .= "}\n";
    file_put_contents($file, $contents);

    $options = [
        'scanMarkers' => true,
        'markerTags' => ['TODO', 'FIXME', 'BUG', 'HACK'],
        'markerSeverity' => ['TODO' => 'low', 'FIXME' => 'high', 'BUG' => 'high', 'HACK' => 'medium'],
        'commentedOutEnabled' => true,
        'allowedReasonTags' => ['TODO', 'FIXME', 'BUG', 'HACK', 'SECURITY', 'REVIEW', 'DEPRECATED'],
        'optionalReasonTags' => ['TEMP', 'DEBUG', 'EXPERIMENTAL'],
        'allowOptionalReasonTagsInStrictMode' => false,
        'minReasonLength' => 12,
        'maxAllowedBlockLines' => 10,
        'requireIssueForBlocksLongerThan' => 3,
        'allowedIssuePatterns' => ['/#\d+/', '/[A-Z]+-\d+/'],
        'allowBlankLineBetweenReasonAndCode' => false,
        'allowReasonBeforeBlockComment' => true,
        'allowBlankLineBetweenReasonAndCodeInBlock' => true,
        'allowPhpdocExamples' => true,
        'phpdocExampleLabels' => ['Example:', 'Examples:', 'Usage:', 'Snippet:', 'Code sample:'],
        'suppressionEnabled' => true,
        'suppressionDirective' => '@phpprobe-ignore',
        'strict' => false,
        'typeSeverity' => [],
        'strictSeverity' => [],
        'ruleEnabled' => [],
        'ruleSeverity' => [],
        'customRules' => [],
        'docMode' => 'hybrid',
        'docCacheEnabled' => true,
        'docCacheFile' => $root.DIRECTORY_SEPARATOR.'.comment-doc-cache.json',
        'explain' => false,
    ];

    $elapsedMs = 0.0;

    try {
        $started = hrtime(true);
        (new CommentScanner())->scan([$file], $options);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
    } finally {
        if (is_file($file)) {
            unlink($file);
        }

        if (is_file($options['docCacheFile'])) {
            unlink($options['docCacheFile']);
        }

        if (is_dir($root)) {
            rmdir($root);
        }
    }

    expect($elapsedMs)->toBeLessThan(3000.0);
});

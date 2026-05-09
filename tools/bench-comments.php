<?php

declare(strict_types=1);

use Infocyph\PHPProbe\Comment\CommentScanner;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpprobe-bench-' . uniqid('', true);
@mkdir($root, 0755, true);
$file = $root . DIRECTORY_SEPARATOR . 'Synthetic.php';

$block = <<<'PHP'
/**
 * Creates an instance from the container.
 *
 * @param string $name The service id.
 * @return object
 */
PHP;

$payload = "<?php\n\nfinal class Synthetic\n{\n";

for ($i = 0; $i < 800; $i++) {
    $payload .= $block . "\n";
    $payload .= "// TODO(#{$i}): restore after migration\n";
    $payload .= "// \$legacy{$i} = \$service->run(\$input);\n";
}

$payload .= "}\n";
file_put_contents($file, $payload);

$baseOptions = [
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
    'explain' => false,
];

foreach (['heuristic', 'hybrid', 'parser'] as $mode) {
    $started = hrtime(true);
    $result = (new CommentScanner())->scan([$file], [...$baseOptions, 'docMode' => $mode]);
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    printf(
        "%s: %.2f ms, findings=%d\n",
        strtoupper($mode),
        $elapsedMs,
        count($result['findings']),
    );
}

@unlink($file);
@rmdir($root);

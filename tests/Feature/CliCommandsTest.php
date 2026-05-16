<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'FixtureSupport.php';

it('runs check command and writes report artifacts', function (): void {
    $root = makeCliFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $reportDir = $root.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'reports';
    $summaryJson = $root.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'check-summary.json';
    $syntaxJsonExists = false;
    $duplicatesMarkdownExists = false;
    $apiSarifExists = false;
    $commentsTextExists = false;
    $summary = [];

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Example.php', <<<'PHP'
<?php

final class Example
{
}
PHP);

    try {
        $run = runCliCommand($root, ['check', '--report-dir=build/reports', '--summary-json=build/check-summary.json', 'src']);
        $summary = json_decode(file_get_contents($summaryJson) ?: 'null', true);
        $syntaxJsonExists = is_file($reportDir.DIRECTORY_SEPARATOR.'syntax.json');
        $duplicatesMarkdownExists = is_file($reportDir.DIRECTORY_SEPARATOR.'duplicates.md');
        $apiSarifExists = is_file($reportDir.DIRECTORY_SEPARATOR.'api.sarif');
        $commentsTextExists = is_file($reportDir.DIRECTORY_SEPARATOR.'comments.text');
    } finally {
        removeCliFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($syntaxJsonExists)->toBeTrue()
        ->and($duplicatesMarkdownExists)->toBeTrue()
        ->and($apiSarifExists)->toBeTrue()
        ->and($commentsTextExists)->toBeTrue()
        ->and($summary['checker'])->toBe('check')
        ->and($summary['exit_code'])->toBe(0);
});

it('aggregates failures in check command output', function (): void {
    $root = makeCliFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Broken.php', <<<'PHP'
<?php

final class Broken
{
PHP);

    try {
        $run = runCliCommand($root, ['check', '--format=json', 'src']);
    } finally {
        removeCliFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['summary']['checks']['syntax'])->toBe(1)
        ->and($result['summary']['exit_code'])->toBe(1);
});

it('supports github annotations in check command output', function (): void {
    $root = makeCliFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Broken.php', <<<'PHP'
<?php

final class Broken
{
PHP);

    try {
        $run = runCliCommand($root, ['check', '--format=github', 'src']);
    } finally {
        removeCliFixture($root);
    }

    expect($run['exitCode'])->toBe(1)
        ->and($run['stdout'])->toContain('::error title=PHPProbe syntax::');
});

it('validates config files through config validate command', function (): void {
    $root = makeCliFixture();
    $bad = $root.DIRECTORY_SEPARATOR.'bad-phpprobe.json';
    file_put_contents($bad, json_encode(['unknown' => true], JSON_PRETTY_PRINT));

    try {
        $run = runCliCommand($root, ['config', 'validate', '--json', '--config=bad-phpprobe.json']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($payload['valid'])->toBeFalse()
        ->and(implode(' ', $payload['errors']))->toContain('root.unknown');
});

it('validates enum values in config validate command', function (): void {
    $root = makeCliFixture();
    $bad = $root.DIRECTORY_SEPARATOR.'bad-enum-phpprobe.json';
    file_put_contents($bad, json_encode([
        'comments' => [
            'doc_mode' => 'smart',
            'fail_confidence' => 'certain',
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $run = runCliCommand($root, ['config', 'validate', '--json', '--config=bad-enum-phpprobe.json']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $joined = implode(' ', $payload['errors'] ?? []);

    expect($run['exitCode'])->toBe(1)
        ->and($joined)->toContain('comments.doc_mode must be one of')
        ->and($joined)->toContain('comments.fail_confidence must be one of');
});

it('validates duplicate output style and score color values in config validate command', function (): void {
    $root = makeCliFixture();
    $bad = $root.DIRECTORY_SEPARATOR.'bad-duplicate-output-phpprobe.json';
    file_put_contents($bad, json_encode([
        'duplicates' => [
            'output' => [
                'style' => 'verbose',
                'score_colors' => [
                    'high' => ['min' => 260, 'color' => 'orange'],
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $run = runCliCommand($root, ['config', 'validate', '--json', '--config=bad-duplicate-output-phpprobe.json']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $joined = implode(' ', $payload['errors'] ?? []);

    expect($run['exitCode'])->toBe(1)
        ->and($joined)->toContain('duplicates.output.style must be one of')
        ->and($joined)->toContain('duplicates.output.score_colors.high.color must be one of');
});

it('validates global output colors in config validate command', function (): void {
    $root = makeCliFixture();
    $bad = $root.DIRECTORY_SEPARATOR.'bad-output-colors-phpprobe.json';
    file_put_contents($bad, json_encode([
        'output' => [
            'colors' => [
                'error' => 'orange',
                'severity' => [
                    'high' => 'purple',
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $run = runCliCommand($root, ['config', 'validate', '--json', '--config=bad-output-colors-phpprobe.json']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $joined = implode(' ', $payload['errors'] ?? []);

    expect($run['exitCode'])->toBe(1)
        ->and($joined)->toContain('output.colors.error must be one of')
        ->and($joined)->toContain('output.colors.severity.high must be one of');
});

it('initializes phpprobe config and ci workflow', function (): void {
    $root = makeCliFixture();
    $config = $root.DIRECTORY_SEPARATOR.'phpprobe.json';
    $workflow = $root.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'workflows'.DIRECTORY_SEPARATOR.'phpprobe.yml';

    try {
        $run = runCliCommand($root, ['init', '--preset=ci', '--path=phpprobe.json', '--with-ci']);
        $contents = json_decode(file_get_contents($config) ?: 'null', true);
        $workflowContent = file_get_contents($workflow) ?: '';
    } finally {
        removeCliFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($contents['preset'])->toBe('ci')
        ->and($workflowContent)->toContain('php vendor/bin/phpprobe check --preset=ci');
});

it('passes comment-specific options through check command', function (): void {
    $root = makeCliFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'DocSnippet.php', <<<'PHP'
<?php

final class DocSnippet
{
    /**
     * $token = $legacy->issue($payload);
     */
    public function issue(): void
    {
    }
}
PHP);

    try {
        $failed = runCliCommand($root, ['check', '--fail-on=warning', 'src']);
        $passed = runCliCommand($root, ['check', '--fail-on=warning', '--fail-confidence=high', '--doc-mode=hybrid', '--explain', 'src']);
    } finally {
        removeCliFixture($root);
    }

    expect($failed['exitCode'])->toBe(1)
        ->and($passed['exitCode'])->toBe(0);
});

it('runs doctor command in json mode', function (): void {
    $root = makeCliFixture();

    try {
        $run = runCliCommand($root, ['doctor', '--json']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBeIn([0, 1])
        ->and(is_array($payload['checks'] ?? null))->toBeTrue();
});

function makeCliFixture(): string
{
    return makeProbeFixture('phpprobe-cli');
}

function removeCliFixture(string $root): void
{
    removeProbeFixture($root);
}

/**
 * @param list<string> $args
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runCliCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpprobe';
    $process = proc_open([PHP_BINARY, $binary, ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start CLI command.');
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exitCode' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

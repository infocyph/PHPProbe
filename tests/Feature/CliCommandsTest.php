<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'FixtureSupport.php';

it('runs check command and writes report artifacts', function (): void {
    $root = makeCliFixture();
    $src = $root . DIRECTORY_SEPARATOR . 'src';
    $reportDir = $root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'reports';
    $summaryJson = $root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'check-summary.json';
    $syntaxJsonExists = false;
    $referenceJsonExists = false;
    $duplicatesJsonExists = false;
    $commentsJsonExists = false;
    $sarifExists = false;
    $markdownExists = false;
    $summary = [];

    mkdir($src, 0755, true);
    file_put_contents($src . DIRECTORY_SEPARATOR . 'Example.php', <<<'PHP'
<?php

final class Example
{
}
PHP);

    try {
        $run = runCliCommand($root, ['check', '--report-dir=build/reports', '--summary-json=build/check-summary.json', 'src']);
        $summary = json_decode(file_get_contents($summaryJson) ?: 'null', true);
        $syntaxJsonExists = is_file($reportDir . DIRECTORY_SEPARATOR . 'syntax.json');
        $referenceJsonExists = is_file($reportDir . DIRECTORY_SEPARATOR . 'reference.json');
        $duplicatesJsonExists = is_file($reportDir . DIRECTORY_SEPARATOR . 'duplicates.json');
        $commentsJsonExists = is_file($reportDir . DIRECTORY_SEPARATOR . 'comments.json');
        $sarifExists = is_file($reportDir . DIRECTORY_SEPARATOR . 'report.sarif');
        $markdownExists = is_file($reportDir . DIRECTORY_SEPARATOR . 'summary.md');
    } finally {
        removeCliFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($syntaxJsonExists)->toBeTrue()
        ->and($referenceJsonExists)->toBeTrue()
        ->and($duplicatesJsonExists)->toBeTrue()
        ->and($commentsJsonExists)->toBeTrue()
        ->and($sarifExists)->toBeTrue()
        ->and($markdownExists)->toBeTrue()
        ->and($summary['checker'])->toBe('check')
        ->and($summary['exit_code'])->toBe(0);
});

it('includes comment findings in aggregate check results', function (): void {
    $root = makeCliFixture();
    $src = $root . DIRECTORY_SEPARATOR . 'src';

    mkdir($src, 0755, true);
    file_put_contents($src . DIRECTORY_SEPARATOR . 'Commented.php', <<<'PHP'
<?php

// SECURITY(auth): rotate the legacy key before deployment.
final class Commented
{
}
PHP);

    try {
        $run = runCliCommand($root, ['check', '--format=json', 'src']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $findings = $payload['results']['comments']['payload']['findings'] ?? [];

    expect($run['exitCode'])->toBe(1)
        ->and($payload['summary']['checks']['comments'])->toBe(1)
        ->and(array_column($findings, 'type'))->toContain('comment_marker');
});

it('includes broken fqcn findings in aggregate check results', function (): void {
    $root = makeCliFixture();
    $src = $root . DIRECTORY_SEPARATOR . 'src';

    mkdir($src, 0755, true);
    file_put_contents($src . DIRECTORY_SEPARATOR . 'BrokenReference.php', <<<'PHP'
<?php

final class BrokenReference extends MissingBaseClass
{
}
PHP);

    try {
        $run = runCliCommand($root, ['check', '--format=json', 'src']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $findings = $payload['results']['reference']['payload']['findings'] ?? [];

    expect($run['exitCode'])->toBe(1)
        ->and($payload['summary']['checks']['reference'])->toBe(1)
        ->and(array_column($findings, 'type'))->toContain('unknown_fqcn');
});

it('preserves the complete standard duplicate profile through check', function (): void {
    $root = makeCliFixture();
    $src = $root . DIRECTORY_SEPARATOR . 'src';

    mkdir($src, 0755, true);
    file_put_contents($src . DIRECTORY_SEPARATOR . 'NearMiss.php', cliNearMissFixture());
    file_put_contents($root . DIRECTORY_SEPARATOR . 'phpprobe.json', json_encode([
        'preset' => 'standard',
        'duplicates' => [
            'min_tokens' => 999,
            'min_statements' => 3,
            'min_similarity' => 0.60,
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $run = runCliCommand($root, ['check', '--config', 'phpprobe.json', '--format=json', 'src']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $clones = $payload['results']['duplicates']['payload']['clones'] ?? [];

    expect($run['exitCode'])->toBe(1)
        ->and(array_column($clones, 'source'))->toContain('near_miss');
});

it('aggregates failures in check command output', function (): void {
    $root = makeCliFixture();
    $src = $root . DIRECTORY_SEPARATOR . 'src';

    mkdir($src, 0755, true);
    file_put_contents($src . DIRECTORY_SEPARATOR . 'Broken.php', <<<'PHP'
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
    $src = $root . DIRECTORY_SEPARATOR . 'src';

    mkdir($src, 0755, true);
    file_put_contents($src . DIRECTORY_SEPARATOR . 'Broken.php', <<<'PHP'
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
    $bad = $root . DIRECTORY_SEPARATOR . 'bad-phpprobe.json';
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

it('validates bounded syntax values in config validate command', function (): void {
    $root = makeCliFixture();
    $bad = $root . DIRECTORY_SEPARATOR . 'bad-enum-phpprobe.json';
    file_put_contents($bad, json_encode([
        'syntax' => [
            'format' => 'xml',
            'parallel' => 0,
            'timeout' => 0,
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
        ->and($joined)->toContain('syntax.format must be one of')
        ->and($joined)->toContain('syntax.parallel must be an integer')
        ->and($joined)->toContain('syntax.timeout must be between');
});

it('validates duplicate output style and score color values in config validate command', function (): void {
    $root = makeCliFixture();
    $bad = $root . DIRECTORY_SEPARATOR . 'bad-duplicate-output-phpprobe.json';
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
    $bad = $root . DIRECTORY_SEPARATOR . 'bad-output-colors-phpprobe.json';
    file_put_contents($bad, json_encode([
        'output' => [
            'colors' => [
                'error' => 'orange',
                'severity' => 'purple',
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
        ->and($joined)->toContain('output.colors.severity is not a supported key');
});

it('initializes phpprobe config and ci workflow', function (): void {
    $root = makeCliFixture();
    $config = $root . DIRECTORY_SEPARATOR . 'phpprobe.json';
    $workflow = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'phpprobe.yml';

    try {
        $run = runCliCommand($root, ['init', '--preset=ci', '--path=phpprobe.json', '--with-ci']);
        $contents = json_decode(file_get_contents($config) ?: 'null', true);
        $workflowContent = file_get_contents($workflow) ?: '';
    } finally {
        removeCliFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($contents['preset'])->toBe('ci')
        ->and($workflowContent)->toContain('php-version: "8.4"')
        ->and($workflowContent)->toContain('php vendor/bin/phpprobe check --preset=ci');
});

it('skips parser-based analysis when syntax fails', function (): void {
    $root = makeCliFixture();
    $src = $root . DIRECTORY_SEPARATOR . 'src';

    mkdir($src, 0755, true);
    file_put_contents($src . DIRECTORY_SEPARATOR . 'Broken.php', <<<'PHP'
<?php

final class Broken
{
PHP);

    try {
        $run = runCliCommand($root, ['check', '--format=json', 'src']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($payload['summary']['skipped'])->toBe(['reference', 'duplicates', 'comments'])
        ->and($payload['results'])->not()->toHaveKey('reference')
        ->and($payload['results'])->not()->toHaveKey('duplicates')
        ->and($payload['results'])->not()->toHaveKey('comments');
});

it('rejects unknown top-level commands', function (): void {
    $root = makeCliFixture();

    try {
        $run = runCliCommand($root, ['unknown']);
    } finally {
        removeCliFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Unknown PHPProbe command: unknown');
});

it('runs doctor command in json mode', function (): void {
    $root = makeCliFixture();

    try {
        $run = runCliCommand($root, ['doctor', '--json']);
    } finally {
        removeCliFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $phpCheck = array_values(array_filter(
        $payload['checks'] ?? [],
        static fn(mixed $check): bool => is_array($check) && ($check['name'] ?? null) === 'php_version',
    ))[0] ?? null;

    expect($run['exitCode'])->toBeIn([0, 1])
        ->and(is_array($payload['checks'] ?? null))->toBeTrue()
        ->and($phpCheck)->toBeArray()
        ->and($phpCheck['message'] ?? null)->toContain('>= 8.4');
});

function makeCliFixture(): string
{
    return makeProbeFixture('phpprobe-cli');
}

function removeCliFixture(string $root): void
{
    removeProbeFixture($root);
}

function cliNearMissFixture(): string
{
    return <<<'PHP'
<?php

function first(array $items): array
{
    $result = [];
    foreach ($items as $item) {
        $result[] = trim((string) $item);
    }
    sort($result);

    return $result;
}

function second(array $values): array
{
    $output = [];
    foreach ($values as $value) {
        $output[] = trim((string) $value);
    }
    $output[] = 'extra';
    sort($output);

    return $output;
}
PHP;
}

/**
 * @param list<string> $args
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runCliCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpprobe';
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

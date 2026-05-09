<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'FixtureSupport.php';

it('loads syntax paths from phpprobe config', function (): void {
    $root = makeSyntaxCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'syntax' => [
            'paths' => ['configured'],
            'exclude' => ['configured/excluded'],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Example.php', <<<'PHP'
<?php

final class Example
{
}
PHP);
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Broken.php', <<<'PHP'
<?php

final class Broken
{
PHP);

    try {
        $run = runSyntaxCheckerCommand($root, []);
    } finally {
        removeSyntaxCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($run['stdout'])->toContain('Syntax OK: 1 PHP files checked.');
});

it('supports excluding syntax paths from CLI arguments', function (): void {
    $root = makeSyntaxCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Example.php', <<<'PHP'
<?php

final class Example
{
}
PHP);
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Broken.php', <<<'PHP'
<?php

final class Broken
{
PHP);

    try {
        $run = runSyntaxCheckerCommand($root, ['configured', '--exclude=configured/excluded']);
    } finally {
        removeSyntaxCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($run['stdout'])->toContain('Syntax OK: 1 PHP files checked.');
});

it('supports parallel syntax linting with json output', function (): void {
    $root = makeSyntaxCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'One.php', <<<'PHP'
<?php

final class One
{
}
PHP);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Two.php', <<<'PHP'
<?php

final class Two
{
PHP);

    try {
        $run = runSyntaxCheckerCommand($root, ['--json', '--parallel=2', 'src']);
    } finally {
        removeSyntaxCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['files_checked'])->toBe(2)
        ->and($result['failures'])->toHaveCount(1);
});

it('rejects unknown syntax command options', function (): void {
    $root = makeSyntaxCheckerFixture();

    try {
        $run = runSyntaxCheckerCommand($root, ['--does-not-exist']);
    } finally {
        removeSyntaxCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Unknown option for syntax command: --does-not-exist');
});

/**
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runSyntaxCheckerCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin' . DIRECTORY_SEPARATOR . 'phpprobe';
    $process = proc_open([PHP_BINARY, $binary, 'syntax', ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start syntax checker.');
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

function makeSyntaxCheckerFixture(): string
{
    return makeProbeFixture('phpprobe-syntax');
}

function removeSyntaxCheckerFixture(string $root): void
{
    removeProbeFixture($root);
}

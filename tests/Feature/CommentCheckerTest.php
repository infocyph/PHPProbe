<?php

declare(strict_types=1);

it('detects comment markers in JSON output', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Marker.php', <<<'PHP'
<?php

// SECURITY(auth): inspect token logging before release
final class Marker
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=info', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['findings'])->not()->toBeEmpty()
        ->and($result['findings'][0]['type'])->toBe('comment_marker')
        ->and($result['findings'][0]['tag'])->toBe('SECURITY');
});

it('reports commented-out code without reason', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'NoReason.php', <<<'PHP'
<?php

// $user = User::find($id);
final class NoReason
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and(array_column($result['findings'], 'type'))->toContain('commented_out_code_without_reason');
});

it('accepts commented-out code with a valid tagged reason', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'ValidReason.php', <<<'PHP'
<?php

// TODO(#123): restore after auth migration is complete
// $token = $legacyAuth->issue($payload);
final class ValidReason
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $types = array_column($result['findings'], 'type');

    expect($run['exitCode'])->toBe(0)
        ->and($types)->toContain('commented_out_code_with_valid_reason');
});

it('reports oversized commented-out blocks', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    $lines = implode(PHP_EOL, array_map(static fn(int $line): string => '// $value'.$line.' = '.$line.';', range(1, 11)));
    file_put_contents($src.DIRECTORY_SEPARATOR.'TooLarge.php', <<<PHP
<?php

// TODO(#77): restore after migration validation
{$lines}
final class TooLarge
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and(array_column($result['findings'], 'type'))->toContain('commented_out_code_block_too_large');
});

it('reports weak tagged reasons', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'WeakReason.php', <<<'PHP'
<?php

// TODO: later
// $legacy = $service->oldMethod();
final class WeakReason
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and(array_column($result['findings'], 'type'))->toContain('commented_out_code_with_weak_reason');
});

it('requires an issue reference for long commented-out blocks', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'IssueRequired.php', <<<'PHP'
<?php

// TODO(auth): restore after auth migration is complete
// $gateway = new LegacyGateway();
// $gateway->setMode('safe');
// $gateway->charge($invoice);
// $gateway->close();
final class IssueRequired
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and(array_column($result['findings'], 'type'))->toContain('commented_out_code_requires_issue_reference');
});

it('ignores PHPDoc usage examples with an example label', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'DocExample.php', <<<'PHP'
<?php

/**
 * Usage:
 *
 * $uid = UID::make();
 * echo $uid->toString();
 */
final class DocExample
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $types = array_column($result['findings'], 'type');

    expect($run['exitCode'])->toBe(0)
        ->and($types)->not()->toContain('commented_out_code_in_phpdoc_without_example_label');
});

it('rejects unknown comment checker options', function (): void {
    $root = makeCommentCheckerFixture();

    try {
        $run = runCommentCheckerCommand($root, ['--does-not-exist']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Unknown option for comments command: --does-not-exist');
});

function makeCommentCheckerFixture(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpprobe-comments-'.uniqid('', true);
    $resources = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpprobe'.DIRECTORY_SEPARATOR.'resources';

    mkdir($root, 0755, true);
    mkdir($resources, 0755, true);
    copy(
        dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpprobe.json',
        $resources.DIRECTORY_SEPARATOR.'phpprobe.json',
    );

    $presetSource = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'presets';
    $presetTarget = $resources.DIRECTORY_SEPARATOR.'presets';
    mkdir($presetTarget, 0755, true);

    foreach (glob($presetSource.DIRECTORY_SEPARATOR.'*.json') ?: [] as $preset) {
        copy($preset, $presetTarget.DIRECTORY_SEPARATOR.basename($preset));
    }

    return $root;
}

function removeCommentCheckerFixture(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($root);
}

/**
 * @param  list<string>  $args
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runCommentCheckerCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin' . DIRECTORY_SEPARATOR . 'phpprobe';
    $process = proc_open([PHP_BINARY, $binary, 'comments', ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start comment checker.');
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

<?php

declare(strict_types=1);

it('writes and compares a public api baseline', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'api-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string'));

    try {
        $write = runApiSnapshotCheckerCommand($root, ['--json', '--write-baseline='.$baseline, 'src']);
        $check = runApiSnapshotCheckerCommand($root, ['--json', '--baseline='.$baseline, 'src']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    $writeResult = json_decode($write['stdout'], true);
    $checkResult = json_decode($check['stdout'], true);

    expect($write['exitCode'])->toBe(0)
        ->and($writeResult['snapshot']['symbols'])->not()->toBeEmpty()
        ->and($check['exitCode'])->toBe(0)
        ->and($checkResult['changed'])->toBeFalse();
});

it('fails when the public api drifts from the baseline', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'api-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string'));

    try {
        runApiSnapshotCheckerCommand($root, ['--write-baseline='.$baseline, 'src']);
        file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('int'));
        $run = runApiSnapshotCheckerCommand($root, ['--json', '--baseline='.$baseline, 'src']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['changed'])->toBeTrue()
        ->and($result['changes']['changed'])->toContain('class Demo\Contract');
});

it('supports fail-on=error to report drift without failing exit code', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'api-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string'));

    try {
        runApiSnapshotCheckerCommand($root, ['--write-baseline='.$baseline, 'src']);
        file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('int'));
        $run = runApiSnapshotCheckerCommand($root, ['--json', '--fail-on=error', '--baseline='.$baseline, 'src']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['changed'])->toBeTrue();
});

it('includes impact classification for changed symbols', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'api-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string'));

    try {
        runApiSnapshotCheckerCommand($root, ['--write-baseline='.$baseline, 'src']);
        file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('int'));
        $run = runApiSnapshotCheckerCommand($root, ['--json', '--baseline='.$baseline, 'src']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $changed = $result['classifications']['changed'][0] ?? null;

    expect($run['exitCode'])->toBe(1)
        ->and($result['impact']['breaking'])->toBeGreaterThanOrEqual(1)
        ->and($changed['impact'] ?? null)->toBe('breaking')
        ->and((string) ($changed['reason'] ?? ''))->toContain('Member signature changed');
});

it('can ignore protected members for public-only snapshots', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'api-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string', 'int'));

    try {
        runApiSnapshotCheckerCommand($root, ['--public-only', '--write-baseline='.$baseline, 'src']);
        file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string', 'string'));
        $run = runApiSnapshotCheckerCommand($root, ['--json', '--public-only', '--baseline='.$baseline, 'src']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['changed'])->toBeFalse();
});

it('loads api paths and excludes from phpprobe config', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'api' => [
            'paths' => ['configured'],
            'exclude' => ['configured/excluded'],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'One.php', apiContractFixture('string'));
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Two.php', <<<'PHP'
<?php

namespace Demo;

final class Hidden
{
    public function name(): string
    {
        return 'hidden';
    }
}
PHP);

    try {
        $run = runApiSnapshotCheckerCommand($root, ['--json']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $ids = array_column($result['snapshot']['symbols'], 'id');

    expect($run['exitCode'])->toBe(0)
        ->and($ids)->toContain('class Demo\Contract')
        ->and($ids)->not()->toContain('class Demo\Hidden');
});

it('rejects unknown api command options', function (): void {
    $root = makeApiSnapshotCheckerFixture();

    try {
        $run = runApiSnapshotCheckerCommand($root, ['--does-not-exist']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Unknown option for api command: --does-not-exist');
});

it('fails when api baseline file is missing', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $missingBaseline = $root.DIRECTORY_SEPARATOR.'missing-api-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string'));

    try {
        $run = runApiSnapshotCheckerCommand($root, ['--json', '--baseline='.$missingBaseline, 'src']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('API baseline file not found');
});

it('fails when api baseline JSON is invalid', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'api-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string'));
    file_put_contents($baseline, '{invalid');

    try {
        $run = runApiSnapshotCheckerCommand($root, ['--json', '--baseline='.$baseline, 'src']);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Invalid API baseline JSON');
});

it('writes summary json output when requested', function (): void {
    $root = makeApiSnapshotCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $summary = $root.DIRECTORY_SEPARATOR.'summary.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Contract.php', apiContractFixture('string'));

    try {
        $run = runApiSnapshotCheckerCommand($root, ['--summary-json='.$summary, 'src']);
        $payload = json_decode(file_get_contents($summary) ?: 'null', true);
    } finally {
        removeApiSnapshotCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($payload['checker'])->toBe('api')
        ->and($payload['exit_code'])->toBe(0);
});

function apiContractFixture(string $returnType, string $protectedReturnType = 'int'): string
{
    return <<<PHP
<?php

namespace Demo;

final class Contract
{
    public const VERSION = '1.0';

    public function name(string \$value): {$returnType}
    {
        return \$value;
    }

    protected function marker(): {$protectedReturnType}
    {
        return 1;
    }

    private function hidden(): void
    {
    }
}
PHP;
}

function makeApiSnapshotCheckerFixture(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpprobe-api-'.uniqid('', true);
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

function removeApiSnapshotCheckerFixture(string $root): void
{
    if (! is_dir($root)) {
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
function runApiSnapshotCheckerCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin' . DIRECTORY_SEPARATOR . 'phpprobe';
    $process = proc_open([PHP_BINARY, $binary, 'api', ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start api snapshot checker.');
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

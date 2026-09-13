<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'FixtureSupport.php';

it('detects a broken fqcn and suggests the closest project symbol', function (): void {
    $root = makeReferenceCheckerFixture();
    writeReferenceComposer($root);
    mkdir($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Contracts', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'PaymentGateway.php', <<<'PHP'
<?php

namespace Acme\Contracts;

interface PaymentGateway {}
PHP);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'OrderService.php', <<<'PHP'
<?php

namespace Acme;

use Acme\Contracts\PaymantGateway;

final class OrderService
{
    public function __construct(private PaymantGateway $gateway) {}
}
PHP);

    try {
        $run = runReferenceCheckerCommand($root, ['--format=json', 'src']);
    } finally {
        removeProbeFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $finding = $payload['findings'][0] ?? [];

    expect($run['exitCode'])->toBe(1)
        ->and($payload['files_checked'])->toBe(2)
        ->and($finding['type'])->toBe('unknown_fqcn')
        ->and($finding['severity'])->toBe('error')
        ->and($finding['symbol'])->toBe('Acme\Contracts\PaymantGateway')
        ->and($finding['confidence'])->toBe('certain')
        ->and($finding['suggestion'])->toContain('Acme\Contracts\PaymentGateway')
        ->and($finding['candidates'][0]['fqcn'])->toBe('Acme\Contracts\PaymentGateway');
});

it('flags an unresolved symbol without candidates as a possible dead reference', function (): void {
    $root = makeReferenceCheckerFixture();
    writeReferenceComposer($root);
    mkdir($root . DIRECTORY_SEPARATOR . 'src', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runner.php', <<<'PHP'
<?php

namespace Acme;

final class Runner
{
    public function run(): void
    {
        new CompletelyRemovedIntegration();
    }
}
PHP);

    try {
        $run = runReferenceCheckerCommand($root, ['--format=json', 'src']);
    } finally {
        removeProbeFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $finding = $payload['findings'][0] ?? [];

    expect($run['exitCode'])->toBe(1)
        ->and($finding['confidence'])->toBe('dead')
        ->and($finding['candidates'])->toBe([])
        ->and($finding['suggestion'])->toContain('dead reference');
});

it('detects declarations that no longer match their psr-4 location', function (): void {
    $root = makeReferenceCheckerFixture();
    writeReferenceComposer($root);
    mkdir($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Billing', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Billing' . DIRECTORY_SEPARATOR . 'Payment.php', <<<'PHP'
<?php

namespace Acme\Legacy;

final class Payment {}
PHP);

    try {
        $run = runReferenceCheckerCommand($root, ['--format=json', 'src']);
    } finally {
        removeProbeFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $finding = $payload['findings'][0] ?? [];

    expect($run['exitCode'])->toBe(1)
        ->and($finding['type'])->toBe('psr4_fqcn_mismatch')
        ->and($finding['symbol'])->toBe('Acme\Legacy\Payment')
        ->and($finding['confidence'])->toBe('certain')
        ->and($finding['candidates'][0]['fqcn'])->toBe('Acme\Billing\Payment');
});

it('accepts project dependency and native class references', function (): void {
    $root = makeReferenceCheckerFixture();
    writeReferenceComposer($root);
    mkdir($root . DIRECTORY_SEPARATOR . 'src', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Valid.php', <<<'PHP'
<?php

namespace Acme;

use DateTimeImmutable;
use PhpParser\ParserFactory;

final class Valid
{
    public function parser(ParserFactory $factory): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
PHP);

    try {
        $run = runReferenceCheckerCommand($root, ['--format=json', '--composer=' . dirname(__DIR__, 2) . '/composer.json', 'src']);
    } finally {
        removeProbeFixture($root);
    }

    $payload = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($payload['findings'])->toBe([])
        ->and($payload['references_checked'])->toBe(3);
});

it('resolves symbols through registered runtime autoloaders', function (): void {
    $root = makeReferenceCheckerFixture();
    $composer = $root . DIRECTORY_SEPARATOR . 'composer.json';
    $symbolFile = $root . DIRECTORY_SEPARATOR . 'LazyDependencySymbol.php';
    file_put_contents($composer, '{}');
    file_put_contents($symbolFile, <<<'PHP'
<?php

namespace PHPProbeFixture;

final class LazyDependencySymbol {}
PHP);
    $loader = static function (string $class) use ($symbolFile): void {
        if ($class === 'PHPProbeFixture\\LazyDependencySymbol') {
            require $symbolFile;
        }
    };
    spl_autoload_register($loader);

    try {
        $index = new Infocyph\PHPProbe\Reference\ReferenceIndex($composer);

        expect(class_exists('PHPProbeFixture\\LazyDependencySymbol', false))->toBeFalse()
            ->and($index->isKnown('PHPProbeFixture\\LazyDependencySymbol'))->toBeTrue()
            ->and(class_exists('PHPProbeFixture\\LazyDependencySymbol', false))->toBeTrue();
    } finally {
        spl_autoload_unregister($loader);
        removeProbeFixture($root);
    }
});

it('treats an autoloader failure as an unresolved symbol', function (): void {
    $root = makeReferenceCheckerFixture();
    $composer = $root . DIRECTORY_SEPARATOR . 'composer.json';
    file_put_contents($composer, '{}');
    $loader = static function (string $class): void {
        if ($class === 'PHPProbeFixture\\BrokenAutoloaderSymbol') {
            throw new RuntimeException('Autoloader failed.');
        }
    };
    spl_autoload_register($loader, true, true);

    try {
        $index = new Infocyph\PHPProbe\Reference\ReferenceIndex($composer);

        expect($index->isKnown('PHPProbeFixture\\BrokenAutoloaderSymbol'))->toBeFalse();
    } finally {
        spl_autoload_unregister($loader);
        removeProbeFixture($root);
    }
});

it('reports composer extensions missing from the active php runtime', function (): void {
    $root = makeReferenceCheckerFixture();
    mkdir($root . DIRECTORY_SEPARATOR . 'src', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
        'require' => [
            'ext-tokenizer' => '*',
            'ext-phpprobe-unavailable' => '*',
        ],
        'require-dev' => [
            'ext-phpprobe-dev-unavailable' => '*',
        ],
        'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Healthy.php', <<<'PHP'
<?php

namespace Acme;

final class Healthy {}
PHP);

    try {
        $run = runReferenceCheckerCommand($root, ['--format=json', 'src']);
    } finally {
        removeProbeFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $findings = array_values(array_filter(
        $payload['findings'] ?? [],
        static fn(array $finding): bool => $finding['type'] === 'missing_extension',
    ));

    expect($run['exitCode'])->toBe(1)
        ->and($payload['extensions_checked'])->toBe(3)
        ->and($payload['extensions_missing'])->toBe(2)
        ->and($findings)->toHaveCount(2)
        ->and($findings[0]['file'])->toBe('composer.json')
        ->and($findings[0]['severity'])->toBe('error')
        ->and($findings[0]['confidence'])->toBe('certain')
        ->and($findings[0]['message'])->toContain('PHP extension')
        ->and($findings[0]['suggestion'])->toContain('php -m')
        ->and($payload['groups'][1]['name'])->toBe('composer')
        ->and($payload['groups'][1]['findings'])->toBe(2);
});

it('supports phpstan compatible reference output', function (): void {
    $root = makeReferenceCheckerFixture();
    mkdir($root . DIRECTORY_SEPARATOR . 'src', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Broken.php', <<<'PHP'
<?php

final class Broken extends MissingBase {}
PHP);

    try {
        $run = runReferenceCheckerCommand($root, ['--format=phpstan-json', 'src']);
    } finally {
        removeProbeFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $message = $payload['files']['src/Broken.php']['messages'][0] ?? [];

    expect($run['exitCode'])->toBe(1)
        ->and($payload['totals']['file_errors'])->toBe(1)
        ->and($message['identifier'])->toBe('reference.unknown_fqcn')
        ->and($message['ignorable'])->toBeFalse();
});

it('loads reference paths and composer metadata from config', function (): void {
    $root = makeReferenceCheckerFixture();
    mkdir($root . DIRECTORY_SEPARATOR . 'app', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.custom.json', json_encode([
        'autoload' => ['psr-4' => ['Domain\\' => 'app/']],
    ], JSON_PRETTY_PRINT));
    file_put_contents($root . DIRECTORY_SEPARATOR . 'phpprobe.json', json_encode([
        'reference' => [
            'paths' => ['app'],
            'composer' => 'composer.custom.json',
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Service.php', <<<'PHP'
<?php

namespace Wrong;

final class Service {}
PHP);

    try {
        $run = runReferenceCheckerCommand($root, ['--format=json']);
    } finally {
        removeProbeFixture($root);
    }

    $payload = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($payload['findings'][0]['candidates'][0]['fqcn'])->toBe('Domain\Service');
});

it('rejects unknown reference checker options', function (): void {
    $root = makeReferenceCheckerFixture();

    try {
        $run = runReferenceCheckerCommand($root, ['--unknown']);
    } finally {
        removeProbeFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Unknown option for reference command: --unknown');
});

function makeReferenceCheckerFixture(): string
{
    return makeProbeFixture('phpprobe-reference');
}

function writeReferenceComposer(string $root): void
{
    file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
        'require' => ['ext-tokenizer' => '*'],
        'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
}

/**
 * @param list<string> $args
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runReferenceCheckerCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpprobe';
    $process = proc_open([PHP_BINARY, $binary, 'reference', ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start reference checker.');
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

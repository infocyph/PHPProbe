<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'FixtureSupport.php';

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

it('does not treat descriptive phpdoc as commented-out code', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Factory.php', <<<'PHP'
<?php

final class Factory
{
    /**
     * Creates a new instance of the given class with dependency injection
     * and optionally calls a method on the instance.
     *
     * This method is a convenience wrapper for the InvocationManager's
     * make() method, providing the ability to create objects with their
     * dependencies injected and optionally execute a specified method.
     *
     * @param string $class The class name to create a new instance of.
     * @param string|bool $method The method to call on the instance, or false to not call a method.
     * @return mixed The newly created instance, or the result of the called method.
     * @throws \RuntimeException
     */
    public function create(string $class, string|bool $method = false): mixed
    {
        return null;
    }
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
        ->and($types)->not()->toContain('commented_out_code_without_valid_reason')
        ->and($types)->not()->toContain('commented_out_code_without_reason')
        ->and($types)->not()->toContain('commented_out_code_in_phpdoc_without_example_label');
});

it('supports disabling comment rules from config', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'comments' => [
            'paths' => ['src'],
            'rules' => [
                'comment_marker' => [
                    'enabled' => false,
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Marker.php', <<<'PHP'
<?php

// SECURITY(auth): inspect token logging before release
final class Marker
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=info']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['findings'])->toBe([]);
});

it('supports overriding comment rule severity from config', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'comments' => [
            'paths' => ['src'],
            'rules' => [
                'commented_out_code_with_weak_reason' => [
                    'severity' => 'info',
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($src.DIRECTORY_SEPARATOR.'WeakReason.php', <<<'PHP'
<?php

// TODO: later
// $legacy = $service->oldMethod();
final class WeakReason
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $weak = array_values(array_filter(
        $result['findings'],
        static fn(array $finding): bool => $finding['type'] === 'commented_out_code_with_weak_reason',
    ));

    expect($run['exitCode'])->toBe(0)
        ->and($weak)->toHaveCount(1)
        ->and($weak[0]['severity'])->toBe('info');
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

it('supports markdown output format', function (): void {
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
        $run = runCommentCheckerCommand($root, ['--format=markdown', '--fail-on=info', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(1)
        ->and($run['stdout'])->toContain('# PHPProbe Comment Report')
        ->and($run['stdout'])->toContain('`comment_marker`');
});

it('supports parser doc mode to avoid phpdoc prose false positives', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Factory.php', <<<'PHP'
<?php

final class Factory
{
    /**
     * Creates a service instance and returns it to caller.
     *
     * @param string $id Service identifier.
     * @return object Resolved service instance.
     */
    public function make(string $id): object
    {
        return new stdClass();
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--doc-mode=parser', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $types = array_column($result['findings'], 'type');

    expect($run['exitCode'])->toBe(0)
        ->and($types)->not()->toContain('commented_out_code_without_reason')
        ->and($types)->not()->toContain('commented_out_code_without_valid_reason');
});

it('respects fail confidence threshold', function (): void {
    $root = makeCommentCheckerFixture();
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
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', '--fail-confidence=high', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $types = array_column($result['findings'], 'type');

    expect($types)->toContain('commented_out_code_in_phpdoc_without_example_label')
        ->and($run['exitCode'])->toBe(0);
});

it('prints explanations when explain mode is enabled', function (): void {
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
        $run = runCommentCheckerCommand($root, ['--fail-on=warning', '--explain', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(1)
        ->and($run['stderr'])->toContain('Why:')
        ->and($run['stderr'])->toContain('Suggestion:');
});

it('supports suppression expiry and reports expired suppressions', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Suppressions.php', <<<'PHP'
<?php

// @phpprobe-ignore commented_out_code_without_reason until=2099-01-01
// $active = true;

// @phpprobe-ignore commented_out_code_without_reason until=2020-01-01
// $expired = true;
final class Suppressions
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
    $commentedOutCount = count(array_filter(
        $types,
        static fn(string $type): bool => str_starts_with($type, 'commented_out_code_'),
    ));

    expect($types)->toContain('expired_suppression_rule')
        ->and($commentedOutCount)->toBeGreaterThanOrEqual(1);
});

it('rejects invalid fail confidence values', function (): void {
    $root = makeCommentCheckerFixture();

    try {
        $run = runCommentCheckerCommand($root, ['--fail-confidence=extreme']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Invalid --fail-confidence value');
});

it('reads doc mode and fail confidence from config', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'comments' => [
            'paths' => ['src'],
            'doc_mode' => 'hybrid',
            'fail_confidence' => 'high',
        ],
    ], JSON_PRETTY_PRINT));
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
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(0);
});

it('does not flag phpdoc generics and array-shapes as commented out code', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'ShapeDoc.php', <<<'PHP'
<?php

final class ShapeDoc
{
    /**
     * @param array<string, list<int>> $items typed payload
     * @return array{ok:bool, data:list<int>}
     */
    public function run(array $items): array
    {
        return ['ok' => true, 'data' => []];
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--doc-mode=hybrid', '--fail-on=error', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $types = array_column($result['findings'], 'type');

    expect($run['exitCode'])->toBe(0)
        ->and($types)->not()->toContain('commented_out_code_without_reason')
        ->and($types)->not()->toContain('commented_out_code_without_valid_reason');
});

it('does not flag multiline param descriptions in phpdoc', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'ParamDoc.php', <<<'PHP'
<?php

final class ParamDoc
{
    /**
     * @param string $name Human readable display name
     *                     used by UI and reporting layers.
     * @return void
     */
    public function save(string $name): void
    {
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--doc-mode=hybrid', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);
    $types = array_column($result['findings'], 'type');

    expect($run['exitCode'])->toBe(0)
        ->and($types)->not()->toContain('commented_out_code_without_reason');
});

it('keeps allowing phpdoc usage examples in hybrid mode', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'UsageDoc.php', <<<'PHP'
<?php

final class UsageDoc
{
    /**
     * Usage:
     * $payload = ['id' => 1];
     * $service->run($payload);
     */
    public function run(array $payload): void
    {
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--doc-mode=hybrid', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(0);
});

it('supports comment baselines for existing findings', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'.phpprobe-comments-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'NoReason.php', <<<'PHP'
<?php

// $user = User::find($id);
final class NoReason
{
}
PHP);

    try {
        $write = runCommentCheckerCommand($root, ['--write-baseline=.phpprobe-comments-baseline.json', '--json', '--fail-on=warning', 'src']);
        $baselineExists = is_file($baseline);
        $run = runCommentCheckerCommand($root, ['--baseline=.phpprobe-comments-baseline.json', '--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $payload = json_decode($run['stdout'], true);

    expect($write['exitCode'])->toBe(0)
        ->and($baselineExists ?? false)->toBeTrue()
        ->and($run['exitCode'])->toBe(0)
        ->and($payload['findings'])->toBe([]);
});

it('reports phpdoc signature inconsistencies and unknown params', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'SignatureDoc.php', <<<'PHP'
<?php

final class SignatureDoc
{
    /**
     * @param string $id
     * @param int $ghost
     * @return string
     */
    public function run(int $id): int
    {
        return $id;
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $types = array_column($payload['findings'], 'type');

    expect($run['exitCode'])->toBe(1)
        ->and($types)->toContain('phpdoc_signature_mismatch')
        ->and($types)->toContain('phpdoc_unknown_param');
});

it('reports invalid phpdoc tag values', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'InvalidDoc.php', <<<'PHP'
<?php

final class InvalidDoc
{
    /**
     * @param array<string,int> items
     */
    public function run(array $items): void
    {
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $types = array_column($payload['findings'], 'type');

    expect($run['exitCode'])->toBe(1)
        ->and($types)->toContain('phpdoc_invalid_tag_value');
});

it('supports symbol-scoped suppressions and detects dead suppressions', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'SymbolSuppress.php', <<<'PHP'
<?php

final class SymbolSuppress
{
    public function run(): void
    {
        // @phpprobe-ignore commented_out_code_without_reason scope=symbol
        // $legacy = true;
    }

    // @phpprobe-ignore commented_out_code_without_reason
    // note: no suppressed finding below
    public function keep(): void
    {
        // $active = true;
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $types = array_column($payload['findings'], 'type');
    $withoutReasonCount = count(array_filter(
        $types,
        static fn(string $type): bool => $type === 'commented_out_code_without_reason',
    ));

    expect($types)->toContain('dead_suppression_rule')
        ->and($withoutReasonCount)->toBe(1);
});

it('supports namespaced symbol selectors for symbol-scoped suppressions', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'NamespacedSymbolSuppress.php', <<<'PHP'
<?php

namespace App\Module;

final class Worker
{
    // @phpprobe-ignore commented_out_code_without_reason scope=symbol symbol=App\Module\Worker::run
    public function run(): void
    {
        // $legacyRun = true;
    }

    public function keep(): void
    {
        // $legacyKeep = true;
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $types = array_column($payload['findings'], 'type');
    $withoutReasonCount = count(array_filter(
        $types,
        static fn(string $type): bool => $type === 'commented_out_code_without_reason',
    ));

    expect($run['exitCode'])->toBe(1)
        ->and($types)->not()->toContain('invalid_suppression_rule')
        ->and($withoutReasonCount)->toBe(1);
});

it('scopes symbol suppressions to the selected class when method names overlap', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'OverlappingRunSymbols.php', <<<'PHP'
<?php

final class Alpha
{
    // @phpprobe-ignore commented_out_code_without_reason scope=symbol symbol=Alpha::run
    public function run(): void
    {
        // $alphaLegacy = true;
    }
}

final class Beta
{
    public function run(): void
    {
        // $betaLegacy = true;
    }
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning', 'src']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $types = array_column($payload['findings'], 'type');
    $withoutReasonCount = count(array_filter(
        $types,
        static fn(string $type): bool => $type === 'commented_out_code_without_reason',
    ));

    expect($run['exitCode'])->toBe(1)
        ->and($types)->not()->toContain('invalid_suppression_rule')
        ->and($withoutReasonCount)->toBe(1);
});

it('detects custom comment rules from config', function (): void {
    $root = makeCommentCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'comments' => [
            'paths' => ['src'],
            'custom_rules' => [
                [
                    'id' => 'credential-marker',
                    'pattern' => '/password\s*=/i',
                    'severity' => 'high',
                    'message' => 'Potential credential assignment marker in comment.',
                    'scope' => 'line',
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT));

    file_put_contents($src.DIRECTORY_SEPARATOR.'CustomRule.php', <<<'PHP'
<?php

// password = hardcoded
final class CustomRule
{
}
PHP);

    try {
        $run = runCommentCheckerCommand($root, ['--json', '--fail-on=warning']);
    } finally {
        removeCommentCheckerFixture($root);
    }

    $payload = json_decode($run['stdout'], true);
    $types = array_column($payload['findings'], 'type');

    expect($run['exitCode'])->toBe(1)
        ->and($types)->toContain('custom_rule_credential_marker');
});

function makeCommentCheckerFixture(): string
{
    return makeProbeFixture('phpprobe-comments');
}

function removeCommentCheckerFixture(string $root): void
{
    removeProbeFixture($root);
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

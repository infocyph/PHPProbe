<?php

declare(strict_types=1);
use Infocyph\PHPProbe\Detection\DuplicateCodeIndex;

require_once __DIR__.DIRECTORY_SEPARATOR.'FixtureSupport.php';

it('detects fuzzy token duplicates across php files', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Alpha.php', <<<'PHP'
<?php

final class Alpha
{
    public function render(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = strtoupper((string) $item);
        }

        return $result;
    }
}
PHP);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Beta.php', <<<'PHP'
<?php

final class Beta
{
    public function build(array $values): array
    {
        $output = [];
        foreach ($values as $value) {
            $output[] = strtoupper((string) $value);
        }

        return $output;
    }
}
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(1);

    $result = json_decode($run['stdout'], true);

    expect($result['clones'])->toHaveCount(1)
        ->and($result['clones'][0]['occurrences'])->toHaveCount(2)
        ->and($result['duplicated_lines'])->toBeGreaterThanOrEqual(10);
});

it('preserves associative array keys during fuzzy normalization', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Alpha.php', <<<'PHP'
<?php

final class Alpha
{
    public function options(array $options): array
    {
        return [
            'alpha' => $this->option($options, 'value'),
            'bravo' => $this->option($options, 'value'),
            'charlie' => $this->option($options, 'value'),
            'delta' => $this->option($options, 'value'),
            'echo' => $this->option($options, 'value'),
            'foxtrot' => $this->option($options, 'value'),
            'golf' => $this->option($options, 'value'),
            'hotel' => $this->option($options, 'value'),
        ];
    }
}
PHP);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Beta.php', <<<'PHP'
<?php

final class Beta
{
    public function options(array $options): array
    {
        return [
            'india' => $this->option($options, 'value'),
            'juliet' => $this->option($options, 'value'),
            'kilo' => $this->option($options, 'value'),
            'lima' => $this->option($options, 'value'),
            'mike' => $this->option($options, 'value'),
            'november' => $this->option($options, 'value'),
            'oscar' => $this->option($options, 'value'),
            'papa' => $this->option($options, 'value'),
        ];
    }
}
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, [
            '--json',
            '--mode=gate',
            '--fuzzy',
            '--min-lines=5',
            '--min-tokens=40',
            'src',
        ]);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('keeps associative keys normalized outside fuzzy mode', function (): void {
    $root = makeDuplicateCheckerFixture();
    $file = $root.DIRECTORY_SEPARATOR.'Options.php';
    file_put_contents($file, <<<'PHP'
<?php

return [
    'alpha' => 'first',
    'bravo' => 'second',
];
PHP);

    try {
        $index = (new DuplicateCodeIndex)->build(
            [$file],
            ['normalize' => true, 'fuzzy' => false],
            false,
        );
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $values = array_column($index['streams'][$file], 'value');

    expect($values)->not->toContain("KEY:'alpha'")
        ->and(array_count_values($values)['STR'] ?? 0)->toBe(4);
});

it('does not extend token clones across function boundaries', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Alpha.php', <<<'PHP'
<?php

final class Alpha
{
    private function parseArgs(array $args): array
    {
        $cli = new CliOptions();
        $options = $this->alphaDefaults();
        $configuredPaths = OptionValues::strings($options, 'paths');
        $cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg, $cli),
            'Unknown option: %s',
        );

        return $this->typedOptions($options);
    }

    private function parseCliOption(array $args, int &$index, array &$options, string $arg, CliOptions $cli): bool
    {
        return $this->parseAlphaOption($args, $index, $options, $arg, $cli);
    }
}
PHP);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Beta.php', <<<'PHP'
<?php

final class Beta
{
    private function parseArgs(array $args): array
    {
        $cli = new CliOptions();
        $options = $this->betaDefaults();
        $configuredPaths = OptionValues::strings($options, 'paths');
        $cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg, $cli),
            'Unsupported argument: %s',
        );

        return $this->typedOptions($options);
    }

    private function parseCliOption(array $args, int &$index, array &$options, string $arg, CliOptions $cli): bool
    {
        return $this->parseBetaOption($args, $index, $options, $arg, $cli);
    }
}
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, [
            '--json',
            '--mode=audit',
            '--fuzzy',
            '--min-lines=5',
            '--min-tokens=90',
            '--min-statements=999',
            '--min-similarity=1',
            'src',
        ]);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('passes when no duplicate reaches the configured threshold', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Solo.php', <<<'PHP'
<?php

final class Solo
{
    public function value(): int
    {
        return 42;
    }
}
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--min-lines=5', '--min-tokens=20', 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('does not report overlapping token windows as separate clones', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Options.php', <<<'PHP'
<?php

final class Options
{
    public function values(array $options): array
    {
        return [
            'alpha' => OptionValues::string($options, 'alpha'),
            'bravo' => OptionValues::string($options, 'bravo'),
            'charlie' => OptionValues::string($options, 'charlie'),
            'delta' => OptionValues::string($options, 'delta'),
            'echo' => OptionValues::string($options, 'echo'),
            'foxtrot' => OptionValues::string($options, 'foxtrot'),
            'golf' => OptionValues::string($options, 'golf'),
            'hotel' => OptionValues::string($options, 'hotel'),
            'india' => OptionValues::string($options, 'india'),
            'juliet' => OptionValues::string($options, 'juliet'),
            'kilo' => OptionValues::string($options, 'kilo'),
            'lima' => OptionValues::string($options, 'lima'),
            'mike' => OptionValues::string($options, 'mike'),
            'november' => OptionValues::string($options, 'november'),
            'oscar' => OptionValues::string($options, 'oscar'),
            'papa' => OptionValues::string($options, 'papa'),
            'quebec' => OptionValues::string($options, 'quebec'),
            'romeo' => OptionValues::string($options, 'romeo'),
            'sierra' => OptionValues::string($options, 'sierra'),
            'tango' => OptionValues::string($options, 'tango'),
        ];
    }
}
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--min-lines=5', '--min-tokens=100', 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['clones'])->not->toBe([]);

    foreach ($result['clones'] as $clone) {
        foreach ($clone['occurrences'] as $leftIndex => $left) {
            foreach (array_slice($clone['occurrences'], $leftIndex + 1) as $right) {
                if ($left['file'] !== $right['file']) {
                    continue;
                }

                expect($left['end_line'] < $right['start_line'] || $right['end_line'] < $left['start_line'])->toBeTrue();
            }
        }
    }
});

it('ignores repeated top-level import blocks', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'One.php', <<<'PHP'
<?php

namespace Fixture\One;

use DateTimeImmutable;
use RuntimeException;
use function array_map;
use const PHP_VERSION;
use Fixture\Shared\{Alpha, Beta, Gamma};
PHP);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Two.php', <<<'PHP'
<?php

namespace Fixture\Two;

use DateTimeImmutable;
use RuntimeException;
use function array_map;
use const PHP_VERSION;
use Fixture\Shared\{Alpha, Beta, Gamma};
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--exact', '--min-lines=1', '--min-tokens=4', 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('keeps trait use and closure captures in the token stream', function (): void {
    $root = makeDuplicateCheckerFixture();
    $file = $root.DIRECTORY_SEPARATOR.'Uses.php';
    file_put_contents($file, <<<'PHP'
<?php

namespace Fixture;

use Vendor\Dependency;

trait SharedBehavior {}

final class Subject
{
    use SharedBehavior;

    public function callback(): Closure
    {
        $captured = 'value';

        return static function () use ($captured): string {
            return $captured;
        };
    }
}
PHP);

    try {
        $index = (new DuplicateCodeIndex)->build(
            [$file],
            ['normalize' => false, 'fuzzy' => false],
            false,
        );
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $values = array_column($index['streams'][$file], 'value');

    expect(array_count_values($values)['T_USE:use'] ?? 0)->toBe(2)
        ->and($values)->not->toContain('T_NAME_QUALIFIED:Vendor\Dependency');
});

it('still detects duplicated code following ignored imports', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    $template = <<<'PHP'
<?php

namespace Fixture\%s;

use DateTimeImmutable;
use RuntimeException;

final class %s
{
    public function normalize(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = strtolower(trim((string) $item));
        }

        sort($result);

        return $result;
    }
}
PHP;
    file_put_contents($src.DIRECTORY_SEPARATOR.'One.php', sprintf($template, 'One', 'One'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Two.php', sprintf($template, 'Two', 'Two'));

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['clones'])->not()->toBeEmpty();
});

it('supports fail-on=error threshold for duplicate percentage', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Alpha.php', duplicateBaselineFixture('Alpha'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Beta.php', duplicateBaselineFixture('Beta'));

    try {
        $run = runDuplicateCheckerCommand($root, [
            '--json',
            '--fuzzy',
            '--min-lines=5',
            '--min-tokens=20',
            '--fail-on=error',
            '--error-duplicate-percentage=100',
            'src',
        ]);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->not()->toBeEmpty();
});

it('renders compact clone summary wording in text output', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Alpha.php', duplicateBaselineFixture('Alpha'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Beta.php', duplicateBaselineFixture('Beta'));

    try {
        $run = runDuplicateCheckerCommand($root, ['--fuzzy', '--min-lines=5', '--min-tokens=20', 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(1)
        ->and($run['stderr'])->toContain('Lines:')
        ->and($run['stderr'])->toContain('Similarity:')
        ->and($run['stderr'])->toContain('Engine: Token')
        ->and($run['stderr'])->toContain('Score:');
});

it('supports classic duplicate output style from config overrides', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'duplicates' => [
            'paths' => ['src'],
            'fuzzy' => true,
            'min_lines' => 5,
            'min_tokens' => 20,
            'output' => [
                'style' => 'classic',
            ],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Alpha.php', duplicateBaselineFixture('Alpha'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Beta.php', duplicateBaselineFixture('Beta'));

    try {
        $run = runDuplicateCheckerCommand($root, []);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(1)
        ->and($run['stderr'])->toContain('lines,')
        ->and($run['stderr'])->toContain('similar')
        ->and($run['stderr'])->not()->toContain('Engine:');
});

it('detects near-miss block clones in audit mode', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'NearMiss.php', <<<'PHP'
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
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--near-miss', '--min-lines=5', '--min-tokens=999', '--min-statements=3', '--min-similarity=0.60', 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['clones'])->not()->toBeEmpty()
        ->and(array_column($result['clones'], 'source'))->toContain('near_miss');
});

it('can write and use a duplicate baseline', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'duplicates-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $write = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', '--write-baseline='.$baseline, 'src']);
        $check = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', '--baseline='.$baseline, 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $writeResult = json_decode($write['stdout'], true);
    $checkResult = json_decode($check['stdout'], true);

    expect($write['exitCode'])->toBe(0)
        ->and($writeResult['clones'])->not()->toBeEmpty()
        ->and($check['exitCode'])->toBe(0)
        ->and($checkResult['clones'])->toBe([]);
});

it('keeps baseline fingerprints stable when duplicate code moves by line', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'duplicates-baseline.json';

    mkdir($src, 0755, true);
    $one = $src.DIRECTORY_SEPARATOR.'One.php';
    $two = $src.DIRECTORY_SEPARATOR.'Two.php';
    file_put_contents($one, duplicateBaselineFixture('One'));
    file_put_contents($two, duplicateBaselineFixture('Two'));

    try {
        $write = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', '--write-baseline='.$baseline, 'src']);
        file_put_contents($one, str_replace("<?php\n", "<?php\n\n\n", duplicateBaselineFixture('One')));
        file_put_contents($two, str_replace("<?php\n", "<?php\n\n\n", duplicateBaselineFixture('Two')));
        $check = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', '--baseline='.$baseline, 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($check['stdout'], true);

    expect($write['exitCode'])->toBe(0)
        ->and($check['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('invalidates duplicate cache by content even when size and mtime are unchanged', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $cache = $root.DIRECTORY_SEPARATOR.'duplicates-cache.json';

    mkdir($src, 0755, true);
    $one = $src.DIRECTORY_SEPARATOR.'One.php';
    $two = $src.DIRECTORY_SEPARATOR.'Two.php';
    file_put_contents($one, duplicateBaselineFixture('One'));
    file_put_contents($two, duplicateBaselineFixture('Two'));
    $mtime = filemtime($two);

    try {
        $first = runDuplicateCheckerCommand($root, ['--json', '--no-fuzzy', '--min-lines=5', '--min-tokens=30', '--cache-file='.$cache, 'src']);
        $changed = str_replace('strtoupper', 'strtolower', duplicateBaselineFixture('Two'));
        file_put_contents($two, $changed);

        if (is_int($mtime)) {
            touch($two, $mtime);
        }

        $second = runDuplicateCheckerCommand($root, ['--json', '--no-fuzzy', '--min-lines=5', '--min-tokens=30', '--cache-file='.$cache, 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $firstResult = json_decode($first['stdout'], true);
    $secondResult = json_decode($second['stdout'], true);

    expect($first['exitCode'])->toBe(1)
        ->and($firstResult['clones'])->not()->toBeEmpty()
        ->and($secondResult['cache_hit'])->toBeFalse()
        ->and($secondResult['clones'])->toBe([]);
});

it('bounds near-miss structural comparisons', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Many.php', <<<'PHP'
<?php

function one(array $values): array { sort($values); return $values; }
function two(array $values): array { rsort($values); return $values; }
function three(array $values): array { shuffle($values); return $values; }
function four(array $values): array { array_reverse($values); return $values; }
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, [
            '--near-miss',
            '--min-lines=1',
            '--min-tokens=999',
            '--min-statements=1',
            '--min-similarity=0.1',
            '--max-near-miss-comparisons=1',
            'src',
        ]);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Near-miss comparison limit exceeded');
});

it('supports ignoring duplicate fingerprints from config', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $fingerprint = '';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $first = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', 'src']);
        $initial = json_decode($first['stdout'], true);
        $fingerprint = $initial['clones'][0]['fingerprint'] ?? '';
        file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
            'duplicates' => [
                'paths' => ['src'],
                'fuzzy' => true,
                'min_lines' => 5,
                'min_tokens' => 20,
                'ignore_fingerprints' => [$fingerprint],
            ],
        ], JSON_PRETTY_PRINT));
        $second = runDuplicateCheckerCommand($root, ['--json']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($second['stdout'], true);

    expect($first['exitCode'])->toBe(1)
        ->and($fingerprint)->not()->toBe('')
        ->and($second['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('loads duplicate options and paths from phpprobe config', function (): void {
    $root = makeDuplicateCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';

    mkdir($configured, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'duplicates' => [
            'paths' => ['configured'],
            'fuzzy' => true,
            'min_lines' => 5,
            'min_tokens' => 20,
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $run = runDuplicateCheckerCommand($root, ['--json']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['clones'])->not()->toBeEmpty();
});

it('supports excluding duplicate paths from phpprobe config', function (): void {
    $root = makeDuplicateCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'duplicates' => [
            'paths' => ['configured'],
            'exclude' => ['configured/excluded'],
            'fuzzy' => true,
            'min_lines' => 5,
            'min_tokens' => 20,
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Solo.php', <<<'PHP'
<?php

final class Solo
{
    public function value(): int
    {
        return 42;
    }
}
PHP);
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $run = runDuplicateCheckerCommand($root, ['--json']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('supports excluding duplicate paths from CLI arguments', function (): void {
    $root = makeDuplicateCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Solo.php', <<<'PHP'
<?php

final class Solo
{
    public function value(): int
    {
        return 42;
    }
}
PHP);
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', 'configured', '--exclude=configured/excluded']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('lets duplicate command paths override phpprobe config paths', function (): void {
    $root = makeDuplicateCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $explicit = $root.DIRECTORY_SEPARATOR.'explicit';

    mkdir($configured, 0755, true);
    mkdir($explicit, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'duplicates' => [
            'paths' => ['configured'],
            'fuzzy' => true,
            'min_lines' => 5,
            'min_tokens' => 20,
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));
    file_put_contents($explicit.DIRECTORY_SEPARATOR.'Solo.php', <<<'PHP'
<?php

final class Solo
{
    public function value(): int
    {
        return 42;
    }
}
PHP);

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', 'explicit']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('uses duplicate presets from config and CLI overrides', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', json_encode([
        'preset' => 'ci',
        'duplicates' => [
            'paths' => ['src'],
            'min_lines' => 5,
            'min_tokens' => 999,
            'min_statements' => 3,
            'min_similarity' => 0.60,
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($src.DIRECTORY_SEPARATOR.'NearMiss.php', nearMissFixture());

    try {
        $ci = runDuplicateCheckerCommand($root, ['--json']);
        $standard = runDuplicateCheckerCommand($root, [
            '--json',
            '--preset=standard',
            '--min-tokens=999',
            '--min-statements=3',
            '--min-similarity=0.60',
        ]);
        $strict = runDuplicateCheckerCommand($root, ['--json', '--preset=strict']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    $ciResult = json_decode($ci['stdout'], true);
    $standardResult = json_decode($standard['stdout'], true);
    $strictResult = json_decode($strict['stdout'], true);

    expect($ci['exitCode'])->toBe(0)
        ->and($ciResult['clones'])->toBe([])
        ->and($standard['exitCode'])->toBe(1)
        ->and(array_column($standardResult['clones'], 'source'))->toContain('near_miss')
        ->and($strict['exitCode'])->toBe(1)
        ->and(array_column($strictResult['clones'], 'source'))->toContain('near_miss');
});

it('reports unknown duplicate presets cleanly', function (): void {
    $root = makeDuplicateCheckerFixture();

    try {
        $run = runDuplicateCheckerCommand($root, ['--preset=unknown', '--json']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Unknown PHPProbe preset "unknown"');
});

it('rejects unknown duplicate command options', function (): void {
    $root = makeDuplicateCheckerFixture();

    try {
        $run = runDuplicateCheckerCommand($root, ['--does-not-exist']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Unknown option for duplicates command: --does-not-exist');
});

it('fails when duplicate baseline file is missing', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $missingBaseline = $root.DIRECTORY_SEPARATOR.'missing-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', '--baseline='.$missingBaseline, 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Duplicate baseline file not found');
});

it('fails when duplicate baseline JSON is invalid', function (): void {
    $root = makeDuplicateCheckerFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'duplicates-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));
    file_put_contents($baseline, '{invalid');

    try {
        $run = runDuplicateCheckerCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', '--baseline='.$baseline, 'src']);
    } finally {
        removeDuplicateCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(2)
        ->and($run['stderr'])->toContain('Invalid duplicate baseline JSON');
});

function duplicateBaselineFixture(string $class): string
{
    return <<<PHP
<?php

final class {$class}
{
    public function names(array \$items): array
    {
        \$result = [];
        foreach (\$items as \$item) {
            \$result[] = strtoupper((string) \$item);
        }

        return \$result;
    }
}
PHP;
}

function nearMissFixture(): string
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

function makeDuplicateCheckerFixture(): string
{
    return makeProbeFixture('phpprobe-duplicates');
}

function removeDuplicateCheckerFixture(string $root): void
{
    removeProbeFixture($root);
}

/**
 * @param  list<string>  $args
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runDuplicateCheckerCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpprobe';
    $process = proc_open([PHP_BINARY, $binary, 'duplicates', ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start duplicate checker.');
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

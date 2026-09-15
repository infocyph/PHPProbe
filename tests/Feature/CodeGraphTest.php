<?php

declare(strict_types=1);

use Infocyph\PHPProbe\Graph\CodeGraphExtractor;

require_once __DIR__ . '/FixtureSupport.php';

it('extracts a deterministic PHP code graph with source provenance', function (): void {
    $root = makeProbeFixture('phpprobe-graph');
    $source = $root . DIRECTORY_SEPARATOR . 'src';
    $file = $source . DIRECTORY_SEPARATOR . 'Service.php';
    mkdir($source, 0755, true);
    file_put_contents($file, <<<'PHP'
<?php

namespace Demo;

use DateTimeImmutable;

interface Contract {}

trait Logger
{
    protected function log(): void {}
}

class BaseService
{
    protected static function bootBase(): void {}
}

/** Runs the primary service operation. */
final readonly class Service extends BaseService implements Contract
{
    use Logger;

    public const NAME = 'service';

    public function run(DateTimeImmutable $at): DateTimeImmutable
    {
        $this->log();
        self::bootLocal();
        parent::bootBase();
        helper();

        return new DateTimeImmutable();
    }

    private static function bootLocal(): void {}
}

function helper(): void {}
PHP);

    try {
        $extractor = new CodeGraphExtractor();
        $first = $extractor->extract([$file], $root);
        $second = $extractor->extract([$file], $root);
    } finally {
        removeProbeFixture($root);
    }

    $nodes = array_column($first['nodes'], null, 'id');
    $edges = array_map(
        static fn(array $edge): string => implode('|', [$edge['source'], $edge['relation'], $edge['target'], $edge['resolution']]),
        $first['edges'],
    );

    expect($first)->toBe($second)
        ->and($first['schema'])->toBe('phpprobe.code-graph')
        ->and($first['schema_version'])->toBe(1)
        ->and($first['root'])->toBe('.')
        ->and($first['files_scanned'])->toBe(1)
        ->and($nodes)->toHaveKeys([
            'file:src/Service.php',
            'namespace:Demo',
            'class-like:Demo\\Contract',
            'class-like:Demo\\Logger',
            'class-like:Demo\\BaseService',
            'class-like:Demo\\Service',
            'method:Demo\\Service::run',
            'constant:Demo\\Service::NAME',
            'function:Demo\\helper',
        ])
        ->and($nodes['class-like:Demo\\Contract']['type'])->toBe('interface')
        ->and($nodes['class-like:Demo\\Logger']['type'])->toBe('trait')
        ->and($nodes['class-like:Demo\\Service']['attributes']['documentation'])->toBe('Runs the primary service operation.')
        ->and($nodes['method:Demo\\Service::run']['attributes']['signature'])->toBe('(DateTimeImmutable $at): DateTimeImmutable')
        ->and($edges)->toContain(
            'class-like:Demo\\Service|extends|class-like:Demo\\BaseService|exact',
            'class-like:Demo\\Service|implements|class-like:Demo\\Contract|exact',
            'class-like:Demo\\Service|uses|class-like:Demo\\Logger|exact',
            'method:Demo\\Service::run|calls|method:Demo\\Service::log|exact',
            'method:Demo\\Service::run|calls|method:Demo\\Service::bootLocal|exact',
            'method:Demo\\Service::run|calls|method:Demo\\BaseService::bootBase|exact',
            'method:Demo\\Service::run|calls|function:Demo\\helper|exact',
            'method:Demo\\Service::run|instantiates|class-like:DateTimeImmutable|exact',
        );
});

it('validates graph-specific configuration', function (): void {
    $root = makeProbeFixture('phpprobe-graph-config');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'invalid.json', json_encode([
        'graph' => [
            'pretty' => 'yes',
            'format' => 'markdown',
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $run = runGraphCli($root, ['config', 'validate', '--json', '--config=invalid.json']);
    } finally {
        removeProbeFixture($root);
    }

    $payload = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
    $errors = implode(' ', $payload['errors']);

    expect($run['exitCode'])->toBe(1)
        ->and($errors)->toContain('graph.format is not a supported key')
        ->and($errors)->toContain('graph.pretty must be a boolean');
});

it('writes the graph command output and rejects invalid source', function (): void {
    $root = makeProbeFixture('phpprobe-graph-cli');
    $source = $root . DIRECTORY_SEPARATOR . 'src';
    $output = $root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'graph.json';
    mkdir($source, 0755, true);
    file_put_contents($source . DIRECTORY_SEPARATOR . 'Valid.php', "<?php\n\nfinal class Valid {}\n");

    try {
        $run = runGraphCli($root, ['graph', '--pretty', '--output', $output, 'src']);
        $payload = json_decode(file_get_contents($output) ?: '', true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'Broken.php', "<?php\n\nfinal class Broken {\n");
        $broken = runGraphCli($root, ['graph', 'src']);
    } finally {
        removeProbeFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($run['stdout'])->toBe('')
        ->and($payload['nodes'])->toBeArray()
        ->and($broken['exitCode'])->toBe(2)
        ->and($broken['stderr'])->toContain('Could not extract code graph from');
});

/**
 * @param list<string> $args
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runGraphCli(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpprobe';
    $process = proc_open([PHP_BINARY, $binary, ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start graph command.');
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

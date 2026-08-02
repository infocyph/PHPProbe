<?php

declare(strict_types=1);

use Infocyph\PHPProbe\Process\ProcRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpprobe-benchmark-' . bin2hex(random_bytes(8));

if (!mkdir($workspace, 0700, true) && !is_dir($workspace)) {
    throw new RuntimeException(sprintf('Unable to create benchmark workspace: %s', $workspace));
}

$body = <<<'PHP'
function calculateInvoice(array $items): int
{
    $total = 0;
    foreach ($items as $item) {
        $price = $item['price'] ?? 0;
        $quantity = $item['quantity'] ?? 1;
        $total += $price * $quantity;
    }

    return $total;
}
PHP;

try {
    for ($index = 0; $index < 100; $index++) {
        $namespace = 'Benchmark\\Module' . $index;
        $noise = str_repeat(sprintf("\nfunction unique%d(): int { return %d; }", $index, $index), 4);
        file_put_contents(
            $workspace . DIRECTORY_SEPARATOR . sprintf('Module%03d.php', $index),
            sprintf("<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\n%s\n%s\n", $namespace, $body, $noise),
        );
    }

    $command = [
        PHP_BINARY,
        dirname(__DIR__) . '/bin/phpprobe',
        'duplicates',
        '--format=json',
        '--color=never',
        '--no-cache',
        '--min-lines=5',
        '--min-tokens=70',
        $workspace,
    ];
    $runner = new ProcRunner();
    $durations = [];
    $peakMemory = 0;

    for ($iteration = 0; $iteration < 6; $iteration++) {
        $started = hrtime(true);
        $result = $runner->run($command, timeout: 120.0);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        if ($result === null || !in_array($result->exitCode, [0, 1], true)) {
            throw new RuntimeException('PHPProbe duplicate benchmark failed to complete.');
        }

        if ($iteration > 0) {
            $durations[] = $elapsed;
        }

        $peakMemory = max($peakMemory, memory_get_peak_usage(true));
    }

    sort($durations, SORT_NUMERIC);
    $average = array_sum($durations) / count($durations);
    $p95Index = (int) ceil(count($durations) * 0.95) - 1;

    fwrite(STDOUT, json_encode([
        'fixture_files' => 100,
        'measured_runs' => count($durations),
        'average_ms' => round($average, 2),
        'p95_ms' => round($durations[$p95Index], 2),
        'harness_peak_memory_mb' => round($peakMemory / 1_048_576, 2),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    $files = glob($workspace . DIRECTORY_SEPARATOR . '*.php') ?: [];

    foreach ($files as $file) {
        unlink($file);
    }

    rmdir($workspace);
}

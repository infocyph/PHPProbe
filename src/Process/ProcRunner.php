<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Process;

final class ProcRunner
{
    private const DEFAULT_OUTPUT_LIMIT = 16_777_216;

    /**
     * @param list<string>|string $command
     */
    public function run(
        array|string $command,
        string $stdin = '',
        ?string $cwd = null,
        float $timeout = 30.0,
        int $outputLimit = self::DEFAULT_OUTPUT_LIMIT,
    ): ?ProcessResult {
        if (!function_exists('proc_open')) {
            return null;
        }

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);

        if (!is_resource($process)) {
            return null;
        }

        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $outputLimitExceeded = false;
        $deadline = microtime(true) + max(0.1, $timeout);
        $statusExitCode = -1;

        while (true) {
            $status = proc_get_status($process);
            $running = $status['running'];

            if ($status['exitcode'] !== -1) {
                $statusExitCode = $status['exitcode'];
            }

            $this->drain($pipes[1], $stdout, $outputLimit);
            $this->drain($pipes[2], $stderr, $outputLimit);

            if ((strlen($stdout) + strlen($stderr)) >= $outputLimit) {
                $outputLimitExceeded = true;
                proc_terminate($process);

                break;
            }

            if (!$running) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);

                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 0, 200_000);
        }

        $this->drain($pipes[1], $stdout, $outputLimit);
        $this->drain($pipes[2], $stderr, $outputLimit);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeExitCode = proc_close($process);

        if ($timedOut) {
            $stderr = rtrim($stderr) . ($stderr === '' ? '' : PHP_EOL) . sprintf('Process timed out after %.2f seconds.', $timeout);
        }

        if ($outputLimitExceeded) {
            $stderr = rtrim($stderr) . ($stderr === '' ? '' : PHP_EOL) . sprintf('Process output exceeded %d bytes.', $outputLimit);
        }

        $exitCode = match (true) {
            $timedOut => 124,
            $outputLimitExceeded => 125,
            $statusExitCode !== -1 => $statusExitCode,
            default => $closeExitCode,
        };

        return new ProcessResult($exitCode, $stdout, $stderr, $timedOut, $outputLimitExceeded);
    }

    /** @param resource $stream */
    private function drain($stream, string &$target, int $outputLimit): void
    {
        $remaining = $outputLimit - strlen($target);

        if ($remaining <= 0) {
            return;
        }

        $chunk = stream_get_contents($stream, $remaining);

        if (is_string($chunk) && $chunk !== '') {
            $target .= $chunk;
        }
    }
}

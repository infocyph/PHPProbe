<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

use Infocyph\PHPProbe\Config\ConfigValidator;

final class ConfigCommand
{
    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        $action = $args[0] ?? 'help';

        return match ($action) {
            'validate' => $this->validate(array_slice($args, 1)),
            default => $this->help(),
        };
    }

    private function help(): int
    {
        fwrite(STDOUT, 'Usage: phpprobe config validate [options]' . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function validate(array $args): int
    {
        $configPath = (getcwd() ?: '.') . DIRECTORY_SEPARATOR . 'phpprobe.json';
        $json = false;

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $json = true;

                continue;
            }

            if (str_starts_with($arg, '--config=')) {
                $configPath = trim(substr($arg, strlen('--config=')));

                continue;
            }

            if ($arg === '--help' || $arg === '-h') {
                return $this->validateHelp();
            }

            fwrite(STDERR, sprintf('Unknown option for config validate command: %s', $arg) . PHP_EOL);

            return 2;
        }

        try {
            $errors = (new ConfigValidator())->validateFile($configPath);
        } catch (\RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }

        if ($json) {
            fwrite(STDOUT, json_encode([
                'config' => $configPath,
                'valid' => $errors === [],
                'errors' => $errors,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } elseif ($errors === []) {
            fwrite(STDOUT, sprintf('Config is valid: %s', $configPath) . PHP_EOL);
        } else {
            fwrite(STDERR, sprintf('Config validation failed: %s', $configPath) . PHP_EOL);

            foreach ($errors as $error) {
                fwrite(STDERR, '  - ' . $error . PHP_EOL);
            }
        }

        return $errors === [] ? 0 : 1;
    }

    private function validateHelp(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe config validate [options]',
            '',
            'Options:',
            '  --config=FILE    config file path (default: ./phpprobe.json)',
            '  --json           emit JSON result',
            '  --help           show this help',
        ]) . PHP_EOL);

        return 0;
    }
}

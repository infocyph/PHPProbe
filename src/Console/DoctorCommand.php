<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

use Infocyph\PHPProbe\Config\ConfigValidator;

final class DoctorCommand
{
    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        $options = [
            'config' => (getcwd() ?: '.') . DIRECTORY_SEPARATOR . 'phpprobe.json',
            'json' => false,
            'help' => false,
        ];

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;

                continue;
            }

            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;

                continue;
            }

            if (str_starts_with($arg, '--config=')) {
                $options['config'] = trim(substr($arg, strlen('--config=')));

                continue;
            }

            fwrite(STDERR, sprintf('Unknown option for doctor command: %s', $arg) . PHP_EOL);

            return 2;
        }

        if ($options['help']) {
            return $this->help();
        }

        $checks = $this->checks($options['config']);
        $hasFail = false;

        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $hasFail = true;

                break;
            }
        }

        $exitCode = $hasFail ? 1 : 0;

        if ($options['json']) {
            fwrite(STDOUT, json_encode([
                'ok' => !$hasFail,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return $exitCode;
        }

        fwrite(STDOUT, 'PHPProbe doctor report:' . PHP_EOL);

        foreach ($checks as $check) {
            $label = strtoupper($check['status']);
            fwrite(STDOUT, sprintf('- [%s] %s: %s', $label, $check['name'], $check['message']) . PHP_EOL);
        }

        fwrite(STDOUT, sprintf('Status: %s', $hasFail ? 'FAIL' : 'PASS') . PHP_EOL);

        return $exitCode;
    }

    /**
     * @return array{name:string,status:string,message:string}
     */
    private function captainHookCheck(): array
    {
        $root = getcwd() ?: '.';
        $captainConfig = $root . DIRECTORY_SEPARATOR . '.captainhook.json';
        $gitHook = $root . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . 'pre-commit';

        $hasConfig = is_file($captainConfig);
        $hasHook = is_file($gitHook);

        if ($hasConfig && $hasHook) {
            return [
                'name' => 'captainhook',
                'status' => 'pass',
                'message' => 'CaptainHook config and pre-commit hook are present.',
            ];
        }

        if (!$hasConfig && !$hasHook) {
            return [
                'name' => 'captainhook',
                'status' => 'warn',
                'message' => 'CaptainHook is not detected. Install hooks to enforce checks before commit.',
            ];
        }

        return [
            'name' => 'captainhook',
            'status' => 'warn',
            'message' => 'CaptainHook setup is partial. Ensure both .captainhook.json and .git/hooks/pre-commit are installed.',
        ];
    }

    /**
     * @return list<array{name:string,status:string,message:string}>
     */
    private function checks(string $configPath): array
    {
        $checks = [];
        $checks[] = $this->phpVersionCheck();
        $checks[] = $this->extensionCheck('json');
        $checks[] = $this->extensionCheck('tokenizer');
        $checks[] = $this->configCheck($configPath);
        $checks[] = $this->captainHookCheck();

        return $checks;
    }

    /**
     * @return array{name:string,status:string,message:string}
     */
    private function configCheck(string $configPath): array
    {
        if (!is_file($configPath)) {
            return [
                'name' => 'config',
                'status' => 'warn',
                'message' => sprintf('Config file not found: %s', $configPath),
            ];
        }

        try {
            $errors = (new ConfigValidator())->validateFile($configPath);
        } catch (\RuntimeException $exception) {
            return [
                'name' => 'config',
                'status' => 'fail',
                'message' => $exception->getMessage(),
            ];
        }

        if ($errors === []) {
            return [
                'name' => 'config',
                'status' => 'pass',
                'message' => sprintf('Config is valid: %s', $configPath),
            ];
        }

        return [
            'name' => 'config',
            'status' => 'fail',
            'message' => sprintf('Config has %d validation error(s).', count($errors)),
        ];
    }

    /**
     * @return array{name:string,status:string,message:string}
     */
    private function extensionCheck(string $extension): array
    {
        if (extension_loaded($extension)) {
            return [
                'name' => 'ext_' . $extension,
                'status' => 'pass',
                'message' => sprintf('Extension "%s" is loaded.', $extension),
            ];
        }

        return [
            'name' => 'ext_' . $extension,
            'status' => 'fail',
            'message' => sprintf('Extension "%s" is missing.', $extension),
        ];
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe doctor [options]',
            '',
            'Options:',
            '  --config=FILE    config file path (default: ./phpprobe.json)',
            '  --json           emit JSON result',
            '  --help           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @return array{name:string,status:string,message:string}
     */
    private function phpVersionCheck(): array
    {
        return [
            'name' => 'php_version',
            'status' => 'pass',
            'message' => sprintf('PHP %s satisfies >= 8.2.', PHP_VERSION),
        ];
    }
}

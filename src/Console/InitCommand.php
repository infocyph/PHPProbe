<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

use Infocyph\PHPProbe\Config\PresetRepository;

final class InitCommand
{
    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        try {
            $options = $this->parseArgs($args);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }

        if ($options['help']) {
            return $this->help();
        }

        try {
            (new PresetRepository())->config($options['preset']);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }

        if (is_file($options['path']) && !$options['force']) {
            fwrite(STDERR, sprintf('Config file already exists: %s (use --force to overwrite)', $options['path']) . PHP_EOL);

            return 2;
        }

        $payload = json_encode(['preset' => $options['preset']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || file_put_contents($options['path'], $payload . PHP_EOL) === false) {
            fwrite(STDERR, sprintf('Failed to write config file: %s', $options['path']) . PHP_EOL);

            return 2;
        }

        if ($options['withCi']) {
            $workflow = getcwd() . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'phpprobe.yml';
            $workflowDir = dirname($workflow);

            if (!is_dir($workflowDir) && !mkdir($workflowDir, 0755, true) && !is_dir($workflowDir)) {
                fwrite(STDERR, sprintf('Failed to create workflow directory: %s', $workflowDir) . PHP_EOL);

                return 2;
            }

            if (is_file($workflow) && !$options['force']) {
                fwrite(STDERR, sprintf('Workflow already exists: %s (use --force to overwrite)', $workflow) . PHP_EOL);

                return 2;
            }

            $contents = $this->workflowTemplate($options['preset']);

            if (file_put_contents($workflow, $contents) === false) {
                fwrite(STDERR, sprintf('Failed to write workflow file: %s', $workflow) . PHP_EOL);

                return 2;
            }
        }

        fwrite(STDOUT, sprintf('Initialized %s with preset "%s".', $options['path'], $options['preset']) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{preset:string,path:string,force:bool,withCi:bool,help:bool}
     */
    private function parseArgs(array $args): array
    {
        $options = [
            'preset' => 'standard',
            'path' => (getcwd() ?: '.') . DIRECTORY_SEPARATOR . 'phpprobe.json',
            'force' => false,
            'withCi' => false,
            'help' => false,
        ];

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;

                continue;
            }

            if ($arg === '--force') {
                $options['force'] = true;

                continue;
            }

            if ($arg === '--with-ci') {
                $options['withCi'] = true;

                continue;
            }

            if (str_starts_with($arg, '--preset=')) {
                $options['preset'] = strtolower(trim(substr($arg, strlen('--preset='))));

                continue;
            }

            if (str_starts_with($arg, '--path=')) {
                $options['path'] = trim(substr($arg, strlen('--path=')));

                continue;
            }

            throw new \InvalidArgumentException(sprintf('Unknown option for init command: %s', $arg));
        }

        return $options;
    }

    private function workflowTemplate(string $preset): string
    {
        return implode(PHP_EOL, [
            'name: PHPProbe',
            '',
            'on:',
            '  pull_request:',
            '  push:',
            '    branches: [ main ]',
            '',
            'jobs:',
            '  phpprobe:',
            '    runs-on: ubuntu-latest',
            '    steps:',
            '      - uses: actions/checkout@v4',
            '      - uses: shivammathur/setup-php@v2',
            '        with:',
            '          php-version: "8.2"',
            '      - run: composer install --no-interaction --prefer-dist',
            sprintf('      - run: php vendor/bin/phpprobe check --preset=%s --report-dir=build/reports src tests', $preset),
        ]) . PHP_EOL;
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe init [options]',
            '',
            'Options:',
            '  --preset=NAME    default, standard, ci, or strict (default: standard)',
            '  --path=FILE      target config path (default: ./phpprobe.json)',
            '  --with-ci        also write .github/workflows/phpprobe.yml',
            '  --force          overwrite existing files',
            '  --help           show this help',
        ]) . PHP_EOL);

        return 0;
    }
}

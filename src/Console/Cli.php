<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Console;

use Infocyph\PHPProbe\ApiSnapshotChecker;
use Infocyph\PHPProbe\CommentChecker;
use Infocyph\PHPProbe\Config\PresetRepository;
use Infocyph\PHPProbe\DuplicateChecker;
use Infocyph\PHPProbe\SyntaxChecker;

final class Cli
{
    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        return match ($command) {
            'syntax' => (new SyntaxChecker())->run(array_slice($argv, 2)),
            'duplicates' => (new DuplicateChecker())->run(array_slice($argv, 2)),
            'api' => (new ApiSnapshotChecker())->run(array_slice($argv, 2)),
            'comments' => (new CommentChecker())->run(array_slice($argv, 2)),
            'check' => (new CheckCommand())->run(array_slice($argv, 2)),
            'init' => (new InitCommand())->run(array_slice($argv, 2)),
            'config' => (new ConfigCommand())->run(array_slice($argv, 2)),
            'presets' => $this->presets(),
            'preset' => $this->preset((string) ($argv[2] ?? '')),
            default => $this->help(),
        };
    }

    private function preset(string $name): int
    {
        if ($name === '') {
            fwrite(STDERR, 'Usage: phpprobe preset <name>' . PHP_EOL);

            return 2;
        }

        try {
            fwrite(STDOUT, rtrim((new PresetRepository())->json($name)) . PHP_EOL);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }

        return 0;
    }

    private function presets(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, (new PresetRepository())->names()) . PHP_EOL);

        return 0;
    }

    private function help(): int
    {
        fwrite(STDOUT, 'Usage: phpprobe syntax|duplicates|api|comments|check [options] [paths...] | config validate | init [options] | presets | preset <name>' . PHP_EOL);

        return 0;
    }
}

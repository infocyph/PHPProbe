<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\OptionValues;
use Infocyph\PHPProbe\Config\Paths;
use Infocyph\PHPProbe\Graph\CodeGraphExtractor;
use Infocyph\PHPProbe\Util\AtomicFileWriter;
use Infocyph\PHPProbe\Util\CheckerRuntime;

/** @phpstan-type GraphOptions array{help:bool,pretty:bool,output:string,root:string,changedOnly:bool,changedBase:string,config:string,paths:list<string>,excludes:list<string>} */
final class GraphCommand
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        return CheckerRuntime::guarded(fn(): int => $this->runWithOptions($this->parseArgs($args)));
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpprobe graph [options] [paths...]',
            '',
            'Options:',
            '  --config=FILE                    read PHPProbe graph settings',
            '  --preset=NAME                    apply preset: default, standard, ci, or strict',
            '  --exclude=PATH                   skip a path (repeatable)',
            '  --changed-only                   extract only changed PHP files from Git diff',
            '  --changed-base=REF               Git base ref used with --changed-only',
            '  --root=DIR                       root used for portable source paths (default: working directory)',
            '  --output=FILE                    atomically write JSON instead of standard output',
            '  --pretty                         pretty-print JSON',
            '  --help                           show this help',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return GraphOptions
     */
    private function parseArgs(array $args): array
    {
        $cli = new CliOptions();
        $options = [
            'help' => false,
            'pretty' => false,
            'output' => '',
            'root' => getcwd() ?: '.',
            'changedOnly' => false,
            'changedBase' => '',
            'config' => Paths::config('phpprobe.json'),
            'paths' => [],
            'excludes' => [],
        ];
        $options = $cli->resolvedConfig($args, $options)->applyGraphOptions($options);
        $configuredPaths = OptionValues::strings($options, 'paths');
        $cli->collectPaths(
            $args,
            $options,
            $configuredPaths,
            fn(string $arg, int &$index, array &$items): bool => $this->parseCliOption($args, $index, $items, $arg, $cli),
            'Unknown option for graph command: %s',
        );

        /** @var GraphOptions $typed */
        $typed = OptionValues::coerce($options, [
            'help' => 'bool',
            'pretty' => 'bool',
            'output' => 'string',
            'root' => 'string',
            'changedOnly' => 'bool',
            'changedBase' => 'string',
            'config' => 'string',
            'paths' => 'strings',
            'excludes' => 'strings',
        ]);

        return $typed;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $options
     */
    private function parseCliOption(array $args, int &$index, array &$options, string $arg, CliOptions $cli): bool
    {
        if ($cli->parseExclude($args, $index, $options, $arg) || $cli->parseChangedOptions($options, $arg)) {
            return true;
        }

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;

            return true;
        }

        if ($arg === '--pretty') {
            $options['pretty'] = true;

            return true;
        }

        if ($arg === '--json') {
            return true;
        }

        return $this->parseValue($args, $index, $options, $arg, $cli, '--output', 'output')
            || $this->parseValue($args, $index, $options, $arg, $cli, '--root', 'root');
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $options
     */
    private function parseValue(
        array $args,
        int &$index,
        array &$options,
        string $arg,
        CliOptions $cli,
        string $name,
        string $key,
    ): bool {
        $value = $cli->optionValue($arg, $name);

        if ($arg === $name) {
            $value = $args[++$index] ?? '';
        }

        if ($value === null) {
            return false;
        }

        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s requires a path.', $name));
        }

        $options[$key] = trim($value);

        return true;
    }

    /** @param GraphOptions $options */
    private function runWithOptions(array $options): int
    {
        if ($options['help']) {
            return $this->help();
        }

        $files = CheckerRuntime::phpFiles($options);
        $graph = new CodeGraphExtractor()->extract($files, $options['root']);
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

        if ($options['pretty']) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($graph, $flags) . PHP_EOL;

        if ($options['output'] !== '') {
            AtomicFileWriter::write($options['output'], $json);

            return 0;
        }

        fwrite(STDOUT, $json);

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

use Infocyph\PHPProbe\Filesystem\PhpFileFinder;

final class CheckerRuntime
{
    /**
     * @param array{color?:mixed} $options
     */
    public static function applyColorMode(array $options): void
    {
        $mode = strtolower(trim((string) ($options['color'] ?? 'auto')));

        if (!in_array($mode, ['auto', 'always', 'never'], true)) {
            return;
        }

        if ($mode === 'auto') {
            putenv('PHPPROBE_COLOR');

            return;
        }

        putenv('PHPPROBE_COLOR=' . $mode);
    }

    /**
     * @param callable():int $work
     */
    public static function guarded(callable $work): int
    {
        try {
            return $work();
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }
    }

    /**
     * @param array{paths:list<string>,excludes:list<string>,changedOnly:bool,changedBase:string} $options
     * @return list<string>
     */
    public static function phpFiles(array $options): array
    {
        return (new PhpFileFinder())->find(
            $options['paths'],
            $options['excludes'],
            ['changedOnly' => $options['changedOnly'], 'changedBase' => $options['changedBase']],
        );
    }
}

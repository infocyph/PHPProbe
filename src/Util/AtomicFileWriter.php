<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class AtomicFileWriter
{
    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Failed to create directory for file write: %s', $directory));
            }
        }

        $temporary = $path . '.tmp.' . self::suffix();
        $written = self::safeFilePutContents($temporary, $contents);

        if ($written === false) {
            throw new \RuntimeException(sprintf('Failed to write temporary file: %s', $temporary));
        }

        if (is_file($path) && !self::safeUnlink($path)) {
            self::safeUnlink($temporary);

            throw new \RuntimeException(sprintf('Failed to replace existing file: %s', $path));
        }

        if (!self::safeRename($temporary, $path)) {
            self::safeUnlink($temporary);

            throw new \RuntimeException(sprintf('Failed to move temporary file into place: %s', $path));
        }
    }

    private static function safeFilePutContents(string $path, string $contents): int|false
    {
        set_error_handler(static fn(): bool => true);

        try {
            return file_put_contents($path, $contents, LOCK_EX);
        } finally {
            restore_error_handler();
        }
    }

    private static function safeRename(string $from, string $to): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return rename($from, $to);
        } finally {
            restore_error_handler();
        }
    }

    private static function safeUnlink(string $path): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return unlink($path);
        } finally {
            restore_error_handler();
        }
    }

    private static function suffix(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return uniqid('', true);
        }
    }
}

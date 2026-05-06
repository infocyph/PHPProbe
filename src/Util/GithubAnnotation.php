<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class GithubAnnotation
{
    public static function emit(
        string $level,
        string $title,
        string $message,
        ?string $file = null,
        ?int $line = null,
    ): string {
        $properties = ['title=' . self::escapeProperty($title)];

        if (is_string($file) && $file !== '') {
            $properties[] = 'file=' . self::escapeProperty($file);
        }

        if (is_int($line) && $line > 0) {
            $properties[] = 'line=' . $line;
        }

        return sprintf(
            '::%s %s::%s',
            self::normalizeLevel($level),
            implode(',', $properties),
            self::escapeMessage($message),
        );
    }

    private static function escapeMessage(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $value,
        );
    }

    private static function escapeProperty(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $value,
        );
    }

    private static function normalizeLevel(string $level): string
    {
        return match (strtolower(trim($level))) {
            'error' => 'error',
            'notice' => 'notice',
            default => 'warning',
        };
    }
}

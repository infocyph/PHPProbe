<?php

declare(strict_types=1);

function makeProbeFixture(string $prefix): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true);
    $resources = $root
        . DIRECTORY_SEPARATOR . 'vendor'
        . DIRECTORY_SEPARATOR . 'infocyph'
        . DIRECTORY_SEPARATOR . 'phpprobe'
        . DIRECTORY_SEPARATOR . 'resources';

    mkdir($root, 0755, true);
    mkdir($resources, 0755, true);

    copy(
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'phpprobe.json',
        $resources . DIRECTORY_SEPARATOR . 'phpprobe.json',
    );

    $presetSource = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'presets';
    $presetTarget = $resources . DIRECTORY_SEPARATOR . 'presets';
    mkdir($presetTarget, 0755, true);

    foreach (glob($presetSource . DIRECTORY_SEPARATOR . '*.json') ?: [] as $preset) {
        copy($preset, $presetTarget . DIRECTORY_SEPARATOR . basename($preset));
    }

    return $root;
}

function removeProbeFixture(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($root);
}

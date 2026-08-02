<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withSkip([
        __DIR__ . '/vendor',
    ])
    ->withPreparedSets(deadCode: true)
    ->withPhpSets()
    ->withPhpVersion(PhpVersion::PHP_82);

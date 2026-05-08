<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class ScopedTempFile
{
    public static function forProject(string $fallbackName, string $scopedPrefix): string
    {
        $tmp = rtrim(sys_get_temp_dir(), '\\/');

        if ($tmp === '') {
            return $fallbackName;
        }

        $scope = substr(hash('sha1', getcwd() ?: ''), 0, 12);

        return $tmp . DIRECTORY_SEPARATOR . $scopedPrefix . '-' . $scope . '.json';
    }
}

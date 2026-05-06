<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

final class Sarif
{
    /**
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>
     */
    public static function payload(array $results): array
    {
        return [
            'version' => '2.1.0',
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => 'PHPProbe',
                        'informationUri' => 'https://github.com/infocyph/phpprobe',
                    ],
                ],
                'results' => $results,
            ]],
        ];
    }
}

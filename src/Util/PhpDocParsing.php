<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Util;

use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

final class PhpDocParsing
{
    public static function lexer(): Lexer
    {
        return new Lexer(new ParserConfig([]));
    }

    public static function parser(): PhpDocParser
    {
        $config = new ParserConfig([]);
        $constExpr = new ConstExprParser($config);
        $type = new TypeParser($config, $constExpr);

        return new PhpDocParser($config, $type, $constExpr);
    }
}

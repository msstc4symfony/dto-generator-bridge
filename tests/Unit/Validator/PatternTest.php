<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use Msstc4Symfony\DtoGeneratorBridge\Validator\Pattern;
use PHPUnit\Framework\TestCase;

final class PatternTest extends TestCase
{
    private const U = '\\u';

    public function testEscapesTheDelimiterUnlessItIsEscapedAlready(): void
    {
        self::assertSame('/^a\/b\/c$/u', Pattern::toPcre('^a/b\/c$'));
        self::assertSame('/[\/]\\\\\//u', Pattern::toPcre('[/]\\\\/'));
        self::assertSame('/a\\\\/u', Pattern::toPcre('a\\\\'));
    }

    public function testTranslatesUnicodeEscapes(): void
    {
        self::assertSame('/\x{00e9}\x{1F600}x/u', Pattern::toPcre(self::U . '00e9' . self::U . '{1F600}x'));
        self::assertSame('/\x{00E9}0/u', Pattern::toPcre(self::U . '00E90'));
    }

    public function testRejectsWhatPcreCannotCompile(): void
    {
        self::assertNull(Pattern::toPcre('(a'));
        self::assertNull(Pattern::toPcre(self::U . '12'));
        self::assertNull(Pattern::toPcre('a\\'));
    }
}

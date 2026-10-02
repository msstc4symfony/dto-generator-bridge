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
        self::assertSame('/^a\/b\/c$/uD', Pattern::toPcre('^a/b\/c$'));
        self::assertSame('/[\/]\\\\\//uD', Pattern::toPcre('[/]\\\\/'));
        self::assertSame('/a\\\\/uD', Pattern::toPcre('a\\\\'));
    }

    public function testTranslatesUnicodeEscapes(): void
    {
        self::assertSame('/\x{E9}\x{1F600}x/uD', Pattern::toPcre(self::U . '00e9' . self::U . '{1F600}x'));
        self::assertSame('/\x{E9}0/uD', Pattern::toPcre(self::U . '00E90'));
    }

    public function testJoinsASurrogatePairIntoOneCodePoint(): void
    {
        self::assertSame('/\x{1F600}/uD', Pattern::toPcre(self::U . 'D83D' . self::U . 'de00'));
        self::assertSame('/\x{10000}/uD', Pattern::toPcre(self::U . 'd800' . self::U . 'DC00'));
        self::assertSame('/\x{10FFFF}/uD', Pattern::toPcre(self::U . 'DBFF' . self::U . 'DFFF'));
        self::assertNull(Pattern::toPcre(self::U . 'D83D'), 'a lone surrogate is no character');
        self::assertNull(Pattern::toPcre(self::U . 'D83D' . self::U . '0041'));
        self::assertNull(Pattern::toPcre(self::U . 'DE00'), 'a low surrogate alone is no character either');
        self::assertNull(Pattern::toPcre(self::U . '{DFFF}'));
    }

    public function testEndsTheSubjectAtTheDollarSign(): void
    {
        $regex = Pattern::toPcre('^a$');

        self::assertNotNull($regex);
        self::assertMatchesRegularExpression($regex, 'a');
        self::assertDoesNotMatchRegularExpression($regex, "a\n");
    }

    public function testRejectsWhatPcreCannotCompile(): void
    {
        self::assertNull(Pattern::toPcre('(a'));
        self::assertNull(Pattern::toPcre(self::U . '12'));
        self::assertNull(Pattern::toPcre('a\\'));
    }
}

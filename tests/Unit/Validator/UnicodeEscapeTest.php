<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use Msstc4Symfony\DtoGeneratorBridge\Validator\UnicodeEscape;
use PHPUnit\Framework\TestCase;

final class UnicodeEscapeTest extends TestCase
{
    private const U = '\\u';

    public function testReadsFourDigitsOrABracedCodePoint(): void
    {
        self::assertSame([0xE9, 4], UnicodeEscape::read('x' . self::U . '00e9z', 3));
        self::assertSame([0x1F600, 7], UnicodeEscape::read(self::U . '{1F600}', 2));
        self::assertNull(UnicodeEscape::read(self::U . '12', 2));
    }

    public function testJoinsASurrogatePair(): void
    {
        self::assertSame([0x1F600, 10], UnicodeEscape::read(self::U . 'D83D' . self::U . 'DE00', 2));
        self::assertSame([0x10000, 10], UnicodeEscape::read(self::U . 'D800' . self::U . 'DC00', 2));
        self::assertSame([0x10FFFF, 10], UnicodeEscape::read(self::U . 'DBFF' . self::U . 'DFFF', 2));
        self::assertSame([0xD7FF, 4], UnicodeEscape::read(self::U . 'D7FF' . self::U . 'DC00', 2));
    }

    public function testRejectsASurrogateLeftAlone(): void
    {
        foreach (['D800', 'DBFF', 'DC00', 'DFFF', '{D83D}'] as $digits) {
            self::assertNull(UnicodeEscape::read(self::U . $digits, 2), $digits);
        }

        self::assertNull(UnicodeEscape::read(self::U . 'D83D' . self::U . '0041', 2));
        self::assertSame([0xE000, 4], UnicodeEscape::read(self::U . 'E000', 2));
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use Msstc4Symfony\DtoGeneratorBridge\Validator\NameCheck;
use PHPUnit\Framework\TestCase;

final class NameCheckTest extends TestCase
{
    public function testComparesNoLengthOfANameThatIsNoUtf8(): void
    {
        self::assertFalse(NameCheck::rejects(['minLength' => 1], "\xff"));
        self::assertFalse(NameCheck::rejects(['maxLength' => 0], "\xff"));
    }

    public function testTakesALengthOfZero(): void
    {
        self::assertTrue(NameCheck::rejects(['maxLength' => 0], 'a'));
        self::assertFalse(NameCheck::rejects(['minLength' => 0, 'maxLength' => 0], ''));
    }
}

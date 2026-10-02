<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use Msstc4Symfony\DtoGeneratorBridge\Validator\Interval;
use PHPUnit\Framework\TestCase;

final class IntervalTest extends TestCase
{
    /**
     * @dataProvider intervals
     *
     * @param int|float $lower
     * @param int|float $upper
     */
    public function testFindsWhetherAValueFits($lower, bool $lowerExclusive, $upper, bool $upperExclusive, bool $integer, bool $empty): void
    {
        self::assertSame($empty, Interval::isEmpty($lower, $lowerExclusive, $upper, $upperExclusive, $integer));
    }

    /**
     * @return array<string, array{int|float, bool, int|float, bool, bool, bool}>
     */
    public static function intervals(): array
    {
        return [
            'numbers between' => [1, true, 2, true, false, false],
            'one number' => [2, false, 2, false, false, false],
            'one number, float and int' => [2.0, false, 2, false, false, false],
            'one number, int and float' => [2, false, 2.0, false, false, false],
            'excluded below' => [2, true, 2, false, false, true],
            'excluded above' => [2, false, 2, true, false, true],
            'crossed' => [3, false, 2, false, false, true],
            'crossed past 2^53' => [9007199254740993, false, 9007199254740992, false, false, true],
            'no integer between neighbours' => [1, true, 2, true, true, true],
            'an integer between' => [1, true, 3, true, true, false],
            'one integer' => [1, false, 1, false, true, false],
            'crossed integers' => [2, false, 1, false, true, true],
            'next integer above an excluded one' => [1, true, 2, false, true, false],
            'integer below an excluded one' => [1, false, 2, true, true, false],
            'integer inside fractions' => [1.5, true, 2.5, true, true, false],
            'excluded whole floats' => [1.0, true, 2.0, true, true, true],
            'below an excluded fraction' => [1.5, true, 2.4, true, true, false],
            'no integer within fractions' => [1.2, false, 1.8, false, true, true],
            'integer at an included float' => [1.2, false, 2.0, false, true, false],
            'integer at a lower float' => [2.0, false, 2.7, false, true, false],
        ];
    }
}

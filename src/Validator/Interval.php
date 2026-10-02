<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

/**
 * Whether a lower and an upper bound leave any value between them.
 */
final class Interval
{
    private function __construct()
    {
    }

    /**
     * @param int|float $lower
     * @param int|float $upper
     * @param bool $integer whether the value is an int, which an interval like (1, 2) leaves without one
     */
    public static function isEmpty($lower, bool $lowerExclusive, $upper, bool $upperExclusive, bool $integer): bool
    {
        if ($integer) {
            return self::smallestInteger($lower, $lowerExclusive) > self::largestInteger($upper, $upperExclusive);
        }

        if ($lower < $upper) {
            return false;
        }

        return $lowerExclusive || $upperExclusive || !self::equal($lower, $upper);
    }

    /**
     * @param int|float $bound
     *
     * @return int|float
     */
    private static function smallestInteger($bound, bool $exclusive)
    {
        if (is_int($bound)) {
            return $exclusive ? $bound + 1 : $bound;
        }

        return $exclusive ? floor($bound) + 1 : ceil($bound);
    }

    /**
     * @param int|float $bound
     *
     * @return int|float
     */
    private static function largestInteger($bound, bool $exclusive)
    {
        if (is_int($bound)) {
            return $exclusive ? $bound - 1 : $bound;
        }

        return $exclusive ? ceil($bound) - 1 : floor($bound);
    }

    /**
     * Two ints compare exactly; past 2^53 their floats may not.
     *
     * @param int|float $a
     * @param int|float $b
     */
    private static function equal($a, $b): bool
    {
        return is_int($a) && is_int($b) ? $a === $b : (float) $a === (float) $b;
    }
}

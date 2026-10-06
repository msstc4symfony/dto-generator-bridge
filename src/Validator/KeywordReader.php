<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The numeric keywords of a value, read as JSON Schema defines them; a value that breaks the definition is reported
 * and left out.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class KeywordReader
{
    /** The first float beyond the int range. */
    private const INT_LIMIT = PHP_INT_MAX + 1;

    private Keywords $keywords;

    private Diagnostics $diagnostics;

    private SchemaLocation $at;

    public function __construct(Keywords $keywords, Diagnostics $diagnostics, SchemaLocation $at)
    {
        $this->keywords = $keywords;
        $this->diagnostics = $diagnostics;
        $this->at = $at;
    }

    /**
     * @return list<int>
     */
    public function counts(string $name): array
    {
        $counts = [];
        foreach ($this->keywords->values($name) as $value) {
            $count = $this->asCount($value);
            if ($count === null) {
                $this->warn($name, 'a non-negative integer');
            } else {
                $counts[] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return list<int|float>
     */
    public function numbers(string $name): array
    {
        $numbers = [];
        foreach ($this->keywords->values($name) as $number) {
            if (is_int($number) || is_float($number)) {
                $numbers[] = $number;
            } else {
                $this->warn($name, 'a number');
            }
        }

        return $numbers;
    }

    /**
     * @return list<int|float>
     */
    public function divisors(string $name): array
    {
        $divisors = [];
        foreach ($this->keywords->values($name) as $divisor) {
            if ((is_int($divisor) || is_float($divisor)) && $divisor > 0) {
                $divisors[] = $divisor;
            } else {
                $this->warn($name, 'a number above zero');
            }
        }

        return $divisors;
    }

    /**
     * JSON Schema counts 2.0 as an integer.
     *
     * @param JsonValue $value
     */
    private function asCount($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        // PHP 8.5 deprecates casting a float beyond the int range.
        if (is_float($value) && $value >= 0 && $value < self::INT_LIMIT && fmod($value, 1.0) === 0.0) {
            return (int) $value;
        }

        return null;
    }

    private function warn(string $name, string $expected): void
    {
        $this->diagnostics->warning(sprintf('"%s" must be %s; it is not checked.', $name, $expected), $this->at);
    }
}

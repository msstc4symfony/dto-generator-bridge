<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The constraints of the keywords JSON Schema applies to one kind of value (strings, numbers, collections) and the
 * ranges of the integer formats.
 *
 * The kind of a value, as the keywords of one kind of value see it; null for a boolean, an enum or a class.
 *
 * @phpstan-type Kind 'string'|'integer'|'number'|'collection'|'several'|null
 *
 * The name of an integer format, as a warning names it beside the keywords.
 * @phpstan-type FormatLabel 'format: int8'|'format: int16'|'format: int32'|'format: uint8'|'format: uint16'|'format: uint32'|'format: uint64'
 *
 * An inclusive bound a format sets, and its name.
 * @phpstan-type ImpliedBound array{int|float, FormatLabel}
 *
 * A bound and the keyword, or format, that sets it.
 * @phpstan-type Bound array{int|float, 'exclusiveMinimum'|'minimum'|'exclusiveMaximum'|'maximum'|FormatLabel}
 *
 * @internal
 */
final class KindConstraints
{
    /**
     * The keywords JSON Schema applies to one kind of value only.
     *
     * @var array{string: list<non-empty-string>, number: list<non-empty-string>, collection: list<non-empty-string>}
     */
    private const KEYWORDS_OF = [
        'string' => ['minLength', 'maxLength', 'pattern'],
        'number' => ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'],
        'collection' => ['minItems', 'maxItems', 'minProperties', 'maxProperties', 'uniqueItems'],
    ];

    /**
     * The bounds of the integer formats, and the name they go by beside the keywords that compete with them; `int64` is
     * PHP's own int on 64-bit platforms and needs none. The literal 2147483648 is a float on 32-bit PHP, so the minimum
     * is computed; the maximum of uint32 stays a float there, which Range compares alike.
     *
     * @var array<non-empty-string, array{int, int|float|null, FormatLabel}>
     */
    private const INTEGER_BOUNDS = [
        'int8' => [-128, 127, 'format: int8'],
        'int16' => [-32768, 32767, 'format: int16'],
        'int32' => [-2147483647 - 1, 2147483647, 'format: int32'],
        'uint8' => [0, 255, 'format: uint8'],
        'uint16' => [0, 65535, 'format: uint16'],
        'uint32' => [0, 4294967295, 'format: uint32'],
        'uint64' => [0, null, 'format: uint64'],
    ];

    private Diagnostics $diagnostics;

    public function __construct(Diagnostics $diagnostics)
    {
        $this->diagnostics = $diagnostics;
    }

    /**
     * Whether the integers of the format get a range.
     */
    public static function rangesIntegersOf(string $format): bool
    {
        return isset(self::INTEGER_BOUNDS[$format]);
    }

    /**
     * The constraints of the keywords of strings, numbers and collections the value is checked for.
     *
     * @return list<ConstraintSpec>
     */
    public function of(Keywords $keywords, KeywordReader $reader, TypeModel $value, SchemaLocation $at): array
    {
        $kind = $this->kindOf($value);

        return array_merge(
            $this->checks($keywords, $kind, 'string', $at) ? $this->strings($keywords, $reader, $at) : [],
            $this->checks($keywords, $kind, 'number', $at) ? $this->numbers($reader, $kind === 'integer', $this->implied($keywords, $kind), $at) : [],
            $this->checks($keywords, $kind, 'collection', $at) ? $this->collections($keywords, $reader, $value) : [],
        );
    }

    /**
     * The constraint of such a format would reject the value's other types, so a value of several gets none.
     */
    public function warnAboutFormatsOfOneKind(Keywords $keywords, TypeModel $value, SchemaLocation $at): void
    {
        if ($this->kindOf($value) !== 'several') {
            return;
        }

        foreach ($keywords->formats() as $format) {
            $integer = isset(self::INTEGER_BOUNDS[$format]);
            $string = FormatConstraints::checks($format);
            if ($integer && $string) {
                $this->diagnostics->warning(sprintf('"format: %s" checks an integer and a string each its own way, and the value may be of several types; it is not checked.', $format), $at);
            } elseif ($integer || $string) {
                $this->diagnostics->warning(sprintf('"format: %s" checks only %s, and the value may be of another type; it is not checked.', $format, $integer ? 'an integer' : 'a string'), $at);
            }
        }
    }

    /**
     * @return Kind
     */
    private function kindOf(TypeModel $value): ?string
    {
        if ($value instanceof ScalarType) {
            $kinds = ['string' => 'string', 'int' => 'integer', 'float' => 'number', 'bool' => null];

            return $kinds[$value->kind()];
        }

        if ($value instanceof ListType || $value instanceof MapType) {
            return 'collection';
        }

        return $value instanceof UnionType || $value instanceof MixedType ? 'several' : null;
    }

    /**
     * Symfony checks a keyword on another kind of value anyway (Length counts the digits of a number), so a value that
     * may be of several types gets none.
     *
     * @param Kind $actual the kind of the value
     * @param 'string'|'number'|'collection' $kind the kind the keywords apply to; an integer is a number
     */
    private function checks(Keywords $keywords, ?string $actual, string $kind, SchemaLocation $at): bool
    {
        $present = array_filter(self::KEYWORDS_OF[$kind], static fn (string $name): bool => $keywords->has($name));
        if ($actual === 'several' && $present !== []) {
            $this->diagnostics->warning(sprintf(
                '"%s" %s only a %s, and the value may be of another type; %s not checked.',
                implode('", "', $present),
                count($present) === 1 ? 'checks' : 'check',
                $kind,
                count($present) === 1 ? 'it is' : 'they are',
            ), $at);
        }

        return $actual === $kind || ($kind === 'number' && $actual === 'integer');
    }

    /**
     * @return list<ConstraintSpec>
     */
    private function strings(Keywords $keywords, KeywordReader $reader, SchemaLocation $at): array
    {
        $constraints = [];
        $length = $this->bounds($reader, 'minLength', 'maxLength');
        if ($length !== []) {
            $constraints[] = new ConstraintSpec('Length', $length);
        }

        foreach ($keywords->values('pattern') as $pattern) {
            $regex = is_string($pattern) ? Pattern::toPcre($pattern) : null;
            if ($regex === null) {
                $this->diagnostics->warning('"pattern" is no regular expression PHP can run; it is not checked.', $at);

                continue;
            }

            $constraints[] = new ConstraintSpec('Regex', [AttributeArgument::named('pattern', ArgumentValue::literal($regex))]);
        }

        return $constraints;
    }

    /**
     * The inclusive bounds the format of an integer sets, each with the name a warning gives it.
     *
     * @param Kind $kind
     *
     * @return array{ImpliedBound, ImpliedBound|null}|null
     */
    private function implied(Keywords $keywords, ?string $kind): ?array
    {
        // Formats of several schemas (an allOf of int32 and int8) leave the values all of them admit.
        $lower = null;
        $upper = null;
        foreach ($kind === 'integer' ? $keywords->formats() : [] as $format) {
            if (isset(self::INTEGER_BOUNDS[$format])) {
                [$min, $max, $label] = self::INTEGER_BOUNDS[$format];
                $lower = $min === max($min, $lower[0] ?? $min) ? [$min, $label] : $lower;
                $upper = $max !== null && $max === min($max, $upper[0] ?? $max) ? [$max, $label] : $upper;
            }
        }

        return $lower === null ? null : [$lower, $upper];
    }

    /**
     * One lower and one upper bound: the strictest of the inclusive and exclusive keywords and the implied bounds,
     * exclusive on a tie.
     *
     * @param array{ImpliedBound, ImpliedBound|null}|null $implied
     *
     * @return list<ConstraintSpec>
     */
    private function numbers(KeywordReader $reader, bool $integer, ?array $implied, SchemaLocation $at): array
    {
        [$impliedLower, $impliedUpper] = $implied ?? [null, null];
        $lower = $this->strictest($reader, 'exclusiveMinimum', 'minimum', true, $impliedLower);
        $upper = $this->strictest($reader, 'exclusiveMaximum', 'maximum', false, $impliedUpper);
        $constraints = [];
        if ($lower !== null && $upper !== null && !$this->satisfiable($lower, $upper, $integer, $at)) {
            $lower = null;
            $upper = null;
        }

        $lowerExclusive = $lower !== null && $lower[1] === 'exclusiveMinimum';
        $upperExclusive = $upper !== null && $upper[1] === 'exclusiveMaximum';
        if ($lower !== null && $upper !== null && !$lowerExclusive && !$upperExclusive) {
            $constraints[] = new ConstraintSpec('Range', [
                AttributeArgument::named('min', ArgumentValue::literal($lower[0])),
                AttributeArgument::named('max', ArgumentValue::literal($upper[0])),
            ]);
        } else {
            if ($lower !== null) {
                $constraints[] = $this->comparison($lowerExclusive ? 'GreaterThan' : 'GreaterThanOrEqual', $lower[0]);
            }

            if ($upper !== null) {
                $constraints[] = $this->comparison($upperExclusive ? 'LessThan' : 'LessThanOrEqual', $upper[0]);
            }
        }

        foreach ($reader->divisors('multipleOf') as $multipleOf) {
            $constraints[] = $this->comparison('DivisibleBy', $multipleOf);
        }

        return $constraints;
    }

    /**
     * The strictest bound and its keyword. On a tie the first candidate stays: an exclusive keyword before an inclusive
     * one, a keyword before an implied bound.
     *
     * @param 'exclusiveMinimum'|'exclusiveMaximum' $exclusive
     * @param 'minimum'|'maximum' $inclusive
     * @param bool $lower whether the greatest bound is the strictest
     * @param ImpliedBound|null $implied an inclusive bound a format sets, and its name
     *
     * @return Bound|null
     */
    private function strictest(
        KeywordReader $reader,
        string $exclusive,
        string $inclusive,
        bool $lower,
        ?array $implied
    ): ?array {
        $candidates = [];
        foreach ([$exclusive, $inclusive] as $keyword) {
            foreach ($reader->numbers($keyword) as $bound) {
                $candidates[] = [$bound, $keyword];
            }
        }

        if ($implied !== null) {
            $candidates[] = $implied;
        }

        $strictest = null;
        foreach ($candidates as $candidate) {
            if ($strictest === null || ($lower ? $candidate[0] > $strictest[0] : $candidate[0] < $strictest[0])) {
                $strictest = $candidate;
            }
        }

        return $strictest;
    }

    /**
     * @param Bound $lower
     * @param Bound $upper
     */
    private function satisfiable(array $lower, array $upper, bool $integer, SchemaLocation $at): bool
    {
        if (!Interval::isEmpty($lower[0], $lower[1] === 'exclusiveMinimum', $upper[0], $upper[1] === 'exclusiveMaximum', $integer)) {
            return true;
        }

        $this->diagnostics->warning(sprintf('"%s" and "%s" leave no valid value; they are not checked.', $lower[1], $upper[1]), $at);

        return false;
    }

    /**
     * JSON Schema applies uniqueItems to arrays only, and the properties of a map to minProperties/maxProperties.
     *
     * @return list<ConstraintSpec>
     */
    private function collections(Keywords $keywords, KeywordReader $reader, TypeModel $value): array
    {
        $isMap = $value instanceof MapType;
        $constraints = [];
        $count = $isMap ? $this->bounds($reader, 'minProperties', 'maxProperties') : $this->bounds($reader, 'minItems', 'maxItems');
        if ($count !== []) {
            $constraints[] = new ConstraintSpec('Count', $count);
        }

        if (!$isMap && in_array(true, $keywords->values('uniqueItems'), true)) {
            $constraints[] = new ConstraintSpec('Unique');
        }

        return $constraints;
    }

    /**
     * @param non-empty-string $minimum
     * @param non-empty-string $maximum
     *
     * @return list<AttributeArgument>
     */
    private function bounds(KeywordReader $reader, string $minimum, string $maximum): array
    {
        $lower = $reader->counts($minimum);
        $upper = $reader->counts($maximum);
        if ($lower !== [] && $upper !== [] && !$reader->consistent($minimum, max($lower), $maximum, min($upper))) {
            return [];
        }

        $bounds = [];
        if ($lower !== []) {
            $bounds[] = AttributeArgument::named('min', ArgumentValue::literal(max($lower)));
        }

        if ($upper !== []) {
            $bounds[] = AttributeArgument::named('max', ArgumentValue::literal(min($upper)));
        }

        return $bounds;
    }

    /**
     * @param non-empty-string $name
     * @param bool|float|int|string $value
     */
    private function comparison(string $name, $value): ConstraintSpec
    {
        return new ConstraintSpec($name, [AttributeArgument::named('value', ArgumentValue::literal($value))]);
    }
}

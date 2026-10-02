<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * The constraints a value's schema and type call for (bridge spec §5.1), in the order of that table.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ConstraintBuilder
{
    /**
     * Format → the constraint that checks it, and the option it needs.
     *
     * @var array<string, array{non-empty-string, ?non-empty-string, string|bool|null}>
     */
    private const FORMATS = [
        'email' => ['Email', 'mode', 'html5'],
        'ipv4' => ['Ip', 'version', '4'],
        'ipv6' => ['Ip', 'version', '6'],
        'hostname' => ['Hostname', 'requireTld', false],
        'uuid' => ['Uuid', null, null],
    ];

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

    private SchemaReferences $references;

    private TargetProfile $target;

    private Diagnostics $diagnostics;

    public function __construct(SchemaReferences $references, TargetProfile $target, Diagnostics $diagnostics)
    {
        $this->references = $references;
        $this->target = $target;
        $this->diagnostics = $diagnostics;
    }

    /**
     * @return list<ConstraintSpec>
     */
    public function build(Schema $schema, TypeModel $type): array
    {
        $keywords = new Keywords($schema, $this->references);
        $value = $type instanceof NullableType ? $type->inner() : $type;
        $at = $schema->location();

        return array_merge(
            $this->checks($keywords, $value, 'string', $at) ? $this->strings($keywords, $at) : [],
            $this->checks($keywords, $value, 'number', $at) ? $this->numbers($keywords, $at) : [],
            $this->checks($keywords, $value, 'collection', $at) ? $this->collections($keywords, $value, $at) : [],
            $this->choices($keywords, $value, $at),
            $this->constants($keywords, $value, $at),
            $this->format($keywords, $value),
            $this->nested($keywords, $value, $at),
        );
    }

    /**
     * Symfony checks a keyword on another kind of value anyway (Length counts the digits of a number), so a value that
     * may be of several types gets none.
     *
     * @param 'string'|'number'|'collection' $kind
     */
    private function checks(Keywords $keywords, TypeModel $value, string $kind, SchemaLocation $at): bool
    {
        $present = array_filter(self::KEYWORDS_OF[$kind], static fn (string $name): bool => $keywords->has($name));
        $actual = $this->kindOf($value);
        if ($actual === 'several' && $present !== []) {
            $this->diagnostics->warning(sprintf(
                '"%s" check only a %s, and the value may be of another type; they are not checked.',
                implode('", "', $present),
                $kind,
            ), $at);
        }

        return $actual === $kind;
    }

    /**
     * @return 'string'|'number'|'collection'|'several'|null
     */
    private function kindOf(TypeModel $value): ?string
    {
        if ($value instanceof ScalarType) {
            $kinds = ['string' => 'string', 'int' => 'number', 'float' => 'number', 'bool' => null];

            return $kinds[$value->kind()];
        }

        if ($value instanceof ListType || $value instanceof MapType) {
            return 'collection';
        }

        return $value instanceof UnionType || $value instanceof MixedType ? 'several' : null;
    }

    /**
     * @return list<ConstraintSpec>
     */
    private function strings(Keywords $keywords, SchemaLocation $at): array
    {
        $constraints = [];
        $length = $this->bounds($keywords, 'minLength', 'maxLength', $at);
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
     * @return list<ConstraintSpec>
     */
    private function numbers(Keywords $keywords, SchemaLocation $at): array
    {
        $minimum = $this->numbersOf($keywords, 'minimum', $at);
        $maximum = $this->numbersOf($keywords, 'maximum', $at);
        $constraints = [];
        if ($minimum !== [] && $maximum !== []) {
            $constraints[] = new ConstraintSpec('Range', [
                AttributeArgument::named('min', ArgumentValue::literal(max($minimum))),
                AttributeArgument::named('max', ArgumentValue::literal(min($maximum))),
            ]);
        } elseif ($minimum !== []) {
            $constraints[] = $this->comparison('GreaterThanOrEqual', max($minimum));
        } elseif ($maximum !== []) {
            $constraints[] = $this->comparison('LessThanOrEqual', min($maximum));
        }

        $exclusiveMinimum = $this->numbersOf($keywords, 'exclusiveMinimum', $at);
        if ($exclusiveMinimum !== []) {
            $constraints[] = $this->comparison('GreaterThan', max($exclusiveMinimum));
        }

        $exclusiveMaximum = $this->numbersOf($keywords, 'exclusiveMaximum', $at);
        if ($exclusiveMaximum !== []) {
            $constraints[] = $this->comparison('LessThan', min($exclusiveMaximum));
        }

        foreach ($this->numbersOf($keywords, 'multipleOf', $at) as $multipleOf) {
            $constraints[] = $this->comparison('DivisibleBy', $multipleOf);
        }

        return $constraints;
    }

    /**
     * @return list<ConstraintSpec>
     */
    private function collections(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        $constraints = [];
        $count = $value instanceof MapType
            ? $this->bounds($keywords, 'minProperties', 'maxProperties', $at)
            : $this->bounds($keywords, 'minItems', 'maxItems', $at);
        if ($count !== []) {
            $constraints[] = new ConstraintSpec('Count', $count);
        }

        if (in_array(true, $keywords->values('uniqueItems'), true)) {
            $constraints[] = new ConstraintSpec('Unique');
        }

        return $constraints;
    }

    /**
     * A PHP enum limits the values itself; where the target has none, an enum is written as a plain string or int, and
     * only the constraint does.
     *
     * @return list<ConstraintSpec>
     */
    private function choices(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        $enum = $keywords->enum();
        if ($enum === null) {
            return [];
        }

        if ($value instanceof EnumType && $this->target->supports(Capability::from(Capability::ENUMS))) {
            if ($keywords->narrowsReferencedEnum()) {
                $this->diagnostics->warning(
                    '"enum" beside "$ref" narrows the referenced enum, which the generated enum type does not; the narrowing is not checked.',
                    $at,
                );
            }

            return [];
        }

        // The generator accepts only string or integer enums, which Choice compares as JSON does.
        $choices = [];
        foreach ($enum as $choice) {
            if (is_int($choice) || is_string($choice)) {
                $choices[] = ArgumentValue::literal($choice);
            }
        }

        return [new ConstraintSpec('Choice', [AttributeArgument::named('choices', ArgumentValue::listOf(...$choices))])];
    }

    /**
     * @return list<ConstraintSpec>
     */
    private function constants(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        $constraints = [];
        foreach ($keywords->values('const') as $constant) {
            if ($constant === null) {
                $constraints[] = new ConstraintSpec('IsNull');
            } elseif ($value instanceof EnumType && $this->target->supports(Capability::from(Capability::ENUMS))) {
                $case = $this->enumCase($value, $constant, $at);
                if ($case instanceof ConstraintSpec) {
                    $constraints[] = $case;
                }
            } elseif (is_int($constant) && $this->isFloat($value)) {
                // JSON has one kind of number, so 1 and 1.0 are the same constant; IdenticalTo compares with ===.
                $constraints[] = $this->comparison('IdenticalTo', (float) $constant);
            } elseif (is_scalar($constant)) {
                $constraints[] = $this->comparison('IdenticalTo', $constant);
            } else {
                $this->diagnostics->warning('"const" with an array or object has no Symfony constraint; it is not checked.', $at);
            }
        }

        return $constraints;
    }

    /**
     * The property holds the enum case, not its value.
     *
     * @param JsonValue $constant
     */
    private function enumCase(EnumType $enum, $constant, SchemaLocation $at): ?ConstraintSpec
    {
        $case = $enum->caseFor($constant);
        if ($case === null) {
            $this->diagnostics->warning('"const" is none of the enum values; it is not checked.', $at);

            return null;
        }

        return new ConstraintSpec('IdenticalTo', [AttributeArgument::named('value', ArgumentValue::constant($case, $enum->className()))]);
    }

    private function isFloat(TypeModel $value): bool
    {
        return $value instanceof ScalarType && $value->kind() === 'float';
    }

    /**
     * A format the config maps to a class gives the property that type, and the class validates itself.
     *
     * @return list<ConstraintSpec>
     */
    private function format(Keywords $keywords, TypeModel $value): array
    {
        $format = $keywords->format();
        if (!$value instanceof ScalarType || $value->kind() !== 'string' || $format === null || !isset(self::FORMATS[$format])) {
            return [];
        }

        [$name, $option, $setting] = self::FORMATS[$format];

        return [new ConstraintSpec($name, $option === null ? [] : [AttributeArgument::named($option, ArgumentValue::literal($setting))])];
    }

    /**
     * Valid traverses arrays itself and may not go inside All, so it stays on the property at any depth.
     *
     * @return list<ConstraintSpec>
     */
    private function nested(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if ($keywords->describesObject()) {
            return [new ConstraintSpec('Valid')];
        }

        $resolved = $keywords->resolved();
        if ($value instanceof ListType) {
            [$item, $itemSchema] = [$value->item(), $resolved->items()];
        } elseif ($value instanceof MapType) {
            [$item, $itemSchema] = [$value->value(), $resolved->additionalProperties()];
        } else {
            return [];
        }

        if (!$itemSchema instanceof Schema) {
            return [];
        }

        $valid = null;
        $inner = [];
        foreach ($this->build($itemSchema, $item) as $constraint) {
            if ($constraint->is('Valid')) {
                $valid = $constraint;
            } else {
                $inner[] = $constraint->toNewInstance();
            }
        }

        $constraints = $valid === null ? [] : [$valid];
        if ($inner !== [] && $this->allowsNew($at)) {
            $constraints[] = new ConstraintSpec('All', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))]);
        }

        return $constraints;
    }

    private function allowsNew(SchemaLocation $at): bool
    {
        if ($this->target->metadata()->isAnnotations() || $this->target->supports(Capability::from(Capability::NEW_IN_INITIALIZERS))) {
            return true;
        }

        $this->diagnostics->warning(sprintf(
            'The constraints of the items go inside All as "new", which PHP %s does not allow in attributes; they are not checked.',
            $this->target->php()->toString(),
        ), $at);

        return false;
    }

    /**
     * @return list<AttributeArgument>
     */
    private function bounds(Keywords $keywords, string $minimum, string $maximum, SchemaLocation $at): array
    {
        $bounds = [];
        $lower = $this->countsOf($keywords, $minimum, $at);
        if ($lower !== []) {
            $bounds[] = AttributeArgument::named('min', ArgumentValue::literal(max($lower)));
        }

        $upper = $this->countsOf($keywords, $maximum, $at);
        if ($upper !== []) {
            $bounds[] = AttributeArgument::named('max', ArgumentValue::literal(min($upper)));
        }

        return $bounds;
    }

    /**
     * @return list<int>
     */
    private function countsOf(Keywords $keywords, string $name, SchemaLocation $at): array
    {
        $counts = [];
        foreach ($keywords->values($name) as $value) {
            $count = $this->asCount($value);
            if ($count === null) {
                $this->diagnostics->warning(sprintf('"%s" must be a non-negative integer; it is not checked.', $name), $at);
            } else {
                $counts[] = $count;
            }
        }

        return $counts;
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

        // A fraction, or a float beyond the int range, does not survive the round trip through int.
        if (is_float($value) && $value >= 0 && (float) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return list<int|float>
     */
    private function numbersOf(Keywords $keywords, string $name, SchemaLocation $at): array
    {
        $numbers = [];
        foreach ($keywords->values($name) as $number) {
            if (is_int($number) || is_float($number)) {
                $numbers[] = $number;
            } else {
                $this->diagnostics->warning(sprintf('"%s" must be a number; it is not checked.', $name), $at);
            }
        }

        return $numbers;
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

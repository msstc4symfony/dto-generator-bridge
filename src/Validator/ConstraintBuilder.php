<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * The constraints a value's schema and type call for (bridge spec §5.1), in the order of that table.
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

    private ValueConstraints $values;

    public function __construct(SchemaReferences $references, TargetProfile $target, Diagnostics $diagnostics)
    {
        $this->references = $references;
        $this->target = $target;
        $this->diagnostics = $diagnostics;
        $this->values = new ValueConstraints($target, $diagnostics);
    }

    /**
     * @return list<ConstraintSpec>
     */
    public function build(Schema $schema, TypeModel $type): array
    {
        $keywords = new Keywords($schema, $this->references);
        $value = $type instanceof NullableType ? $type->inner() : $type;
        $at = $schema->location();
        $reader = new KeywordReader($keywords, $this->diagnostics, $at);

        return array_merge(
            $this->checks($keywords, $value, 'string', $at) ? $this->strings($keywords, $reader, $at) : [],
            $this->checks($keywords, $value, 'number', $at) ? $this->numbers($reader, $at) : [],
            $this->checks($keywords, $value, 'collection', $at) ? $this->collections($keywords, $reader, $value, $at) : [],
            $this->values->choices($keywords, $value, $at),
            $this->values->constants($keywords, $value, $at),
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
                '"%s" %s only a %s, and the value may be of another type; %s not checked.',
                implode('", "', $present),
                count($present) === 1 ? 'checks' : 'check',
                $kind,
                count($present) === 1 ? 'it is' : 'they are',
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
    private function strings(Keywords $keywords, KeywordReader $reader, SchemaLocation $at): array
    {
        $constraints = [];
        $length = $this->bounds($reader, 'minLength', 'maxLength', $at);
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
    private function numbers(KeywordReader $reader, SchemaLocation $at): array
    {
        $minimum = $reader->numbers('minimum');
        $maximum = $reader->numbers('maximum');
        $constraints = [];
        if ($minimum !== [] && $maximum !== []) {
            if ($this->consistent('minimum', max($minimum), 'maximum', min($maximum), $at)) {
                $constraints[] = new ConstraintSpec('Range', [
                    AttributeArgument::named('min', ArgumentValue::literal(max($minimum))),
                    AttributeArgument::named('max', ArgumentValue::literal(min($maximum))),
                ]);
            }
        } elseif ($minimum !== []) {
            $constraints[] = $this->comparison('GreaterThanOrEqual', max($minimum));
        } elseif ($maximum !== []) {
            $constraints[] = $this->comparison('LessThanOrEqual', min($maximum));
        }

        $exclusiveMinimum = $reader->numbers('exclusiveMinimum');
        if ($exclusiveMinimum !== []) {
            $constraints[] = $this->comparison('GreaterThan', max($exclusiveMinimum));
        }

        $exclusiveMaximum = $reader->numbers('exclusiveMaximum');
        if ($exclusiveMaximum !== []) {
            $constraints[] = $this->comparison('LessThan', min($exclusiveMaximum));
        }

        foreach ($reader->divisors('multipleOf') as $multipleOf) {
            $constraints[] = $this->comparison('DivisibleBy', $multipleOf);
        }

        return $constraints;
    }

    /**
     * JSON Schema applies uniqueItems to arrays only, and the properties of a map to minProperties/maxProperties.
     *
     * @return list<ConstraintSpec>
     */
    private function collections(Keywords $keywords, KeywordReader $reader, TypeModel $value, SchemaLocation $at): array
    {
        $isMap = $value instanceof MapType;
        $constraints = [];
        $count = $isMap
            ? $this->bounds($reader, 'minProperties', 'maxProperties', $at)
            : $this->bounds($reader, 'minItems', 'maxItems', $at);
        if ($count !== []) {
            $constraints[] = new ConstraintSpec('Count', $count);
        }

        if (!$isMap && in_array(true, $keywords->values('uniqueItems'), true)) {
            $constraints[] = new ConstraintSpec('Unique');
        }

        return $constraints;
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
    private function bounds(KeywordReader $reader, string $minimum, string $maximum, SchemaLocation $at): array
    {
        $lower = $reader->counts($minimum);
        $upper = $reader->counts($maximum);
        if ($lower !== [] && $upper !== [] && !$this->consistent($minimum, max($lower), $maximum, min($upper), $at)) {
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
     * @param int|float $lower
     * @param int|float $upper
     */
    private function consistent(string $minimum, $lower, string $maximum, $upper, SchemaLocation $at): bool
    {
        if ($lower <= $upper) {
            return true;
        }

        $this->diagnostics->warning(sprintf('"%s" is above "%s", so no value is valid; they are not checked.', $minimum, $maximum), $at);

        return false;
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

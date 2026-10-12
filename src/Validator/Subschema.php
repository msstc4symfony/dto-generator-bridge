<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * A schema the generator keeps as raw JSON (`contains`, `propertyNames`, a pattern's, a branch of `if`), for one value.
 * Only a flat one is read: its keywords become constraints of the value through ConstraintBuilder. On a value of a
 * known type, `type` is only checked to admit every value of it, as no constraint would check a narrower one; on a
 * value of any type it gives a Type constraint.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Subschema
{
    private const KEYWORDS = ['const', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'];

    private const ANNOTATIONS = ['title', 'description', 'examples', '$comment', 'default', 'deprecated'];

    /** The keywords that check a value of any kind alike; the others check one kind of value. */
    private const ANY_KIND = ['type', 'const', 'enum'];

    /** The JSON type of each PHP scalar. */
    private const JSON_TYPES = ['string' => SchemaType::STRING, 'int' => SchemaType::INTEGER, 'float' => SchemaType::NUMBER, 'bool' => SchemaType::BOOLEAN];

    /**
     * The Type constraint's names of each JSON type a value of any type is checked for.
     *
     * @var array<string, non-empty-list<non-empty-string>>
     */
    private const PHP_TYPES = [SchemaType::STRING => ['string'], SchemaType::INTEGER => ['int'], SchemaType::NUMBER => ['int', 'float'], SchemaType::BOOLEAN => ['bool']];

    private Schema $schema;

    private bool $excludesNull;

    private TypeModel $type;

    /** @var list<SchemaType> the types a Type constraint checks: none on a value of a known type */
    private array $checkedTypes;

    /**
     * @param list<SchemaType> $checkedTypes
     */
    private function __construct(Schema $schema, bool $excludesNull, TypeModel $type, array $checkedTypes)
    {
        $this->schema = $schema;
        $this->excludesNull = $excludesNull;
        $this->type = $type;
        $this->checkedTypes = $checkedTypes;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    /**
     * Whether `null` fails the schema, which its constraints let pass: a nullable value then needs NotNull too.
     */
    public function excludesNull(): bool
    {
        return $this->excludesNull;
    }

    /**
     * The type the keywords are checked for: the value's own, or the one `type` names for a value of any type.
     */
    public function type(): TypeModel
    {
        return $this->type;
    }

    /**
     * The Type constraint of a value of any type, for the JSON types `type` allows besides null.
     */
    public function typeConstraint(): ?ConstraintSpec
    {
        $names = [];
        foreach ($this->checkedTypes as $type) {
            foreach (self::PHP_TYPES[$type->value()] as $name) {
                $names[$name] = $name;
            }
        }

        $names = array_values($names);
        if ($names === []) {
            return null;
        }

        $type = count($names) === 1 ? ArgumentValue::literal($names[0]) : ArgumentValue::listOf(...array_map(static fn (string $name): ArgumentValue => ArgumentValue::literal($name), $names));

        return new ConstraintSpec('Type', [AttributeArgument::named('type', $type)]);
    }

    /**
     * @param non-empty-string $keyword
     * @param JsonValue $value
     * @param TypeModel $type the type of each value the schema describes
     */
    public static function read(string $keyword, $value, TypeModel $type, SchemaLocation $at, Diagnostics $diagnostics): ?self
    {
        $builder = new SchemaBuilder($at->child($keyword));
        $inner = $type instanceof NullableType ? $type->inner() : $type;
        if ($value === true) {
            return new self($builder->build(), false, $inner, []);
        }

        if (!is_array($value) || ($value !== [] && Json::isList($value))) {
            $diagnostics->warning(sprintf('"%s" is checked only with a schema object or true; it is not checked.', $keyword), $at);

            return null;
        }

        $checked = [];
        foreach (array_keys($value) as $key) {
            $name = (string) $key;
            if (!self::annotates($name)) {
                $checked[] = $name;
            }
        }

        if (!$inner instanceof ScalarType && !$inner instanceof EnumType && !$inner instanceof MixedType && $checked !== []) {
            $diagnostics->warning(sprintf('"%s" is checked only on values of a string, number, boolean or enum type, or of any type; it is not checked.', $keyword), $at);

            return null;
        }

        $ofOneKind = array_values(array_diff($checked, self::ANY_KIND));
        if ($inner instanceof EnumType && $ofOneKind !== []) {
            $diagnostics->warning(sprintf('"%s" is checked on a value of an enum type only with "type", "const" and "enum"; with "%s" it is not checked.', $keyword, $ofOneKind[0]), $at);

            return null;
        }

        $excludesNull = false;
        $types = [];
        foreach ($value as $name => $listed) {
            $name = (string) $name;
            $setting = Json::value($listed);
            if ($name === 'type') {
                $types = self::types($setting);
                if ($types === null || (!$inner instanceof MixedType && !self::admits($types, $inner))) {
                    $diagnostics->warning(sprintf('"%s" is checked only when its "type" admits every element; it is not checked.', $keyword), $at);

                    return null;
                }

                $excludesNull = $excludesNull || !in_array(SchemaType::NULL, self::names($types), true);
            } elseif ($name === 'enum') {
                if (!is_array($setting) || $setting === [] || !Json::isList($setting)) {
                    $diagnostics->warning(sprintf('"%s" is checked only with a non-empty list in "enum"; it is not checked.', $keyword), $at);

                    return null;
                }

                $enum = [];
                foreach ($setting as $option) {
                    $enum[] = Json::value($option);
                }

                $builder->enum($enum);
                $excludesNull = $excludesNull || !in_array(null, $enum, true);
            } elseif ($name === 'format' && is_string($setting)) {
                $builder->format($setting);
            } elseif (in_array($name, self::KEYWORDS, true)) {
                $builder->keyword($name, $setting);
                $excludesNull = $excludesNull || ($name === 'const' && $setting !== null);
            } elseif (!self::annotates($name)) {
                $diagnostics->warning(sprintf(
                    '"%s" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "%s" it is not checked.',
                    $keyword,
                    $name,
                ), $at);

                return null;
            }
        }

        if (!$inner instanceof MixedType) {
            return new self($builder->build(), $excludesNull, $inner, []);
        }

        return self::ofAnyType($keyword, $builder->build(), $excludesNull, $types, $ofOneKind !== [], $at, $diagnostics);
    }

    /**
     * A value of any type is checked for the types `type` names; the keywords of one kind of value need exactly one,
     * as Symfony checks them on other kinds too (Length counts the digits of a number).
     *
     * @param list<SchemaType> $types
     */
    private static function ofAnyType(string $keyword, Schema $schema, bool $excludesNull, array $types, bool $ofOneKind, SchemaLocation $at, Diagnostics $diagnostics): ?self
    {
        $named = [];
        $scalar = true;
        foreach ($types as $type) {
            if ($type->value() !== SchemaType::NULL) {
                $named[] = $type;
                $scalar = $scalar && isset(self::PHP_TYPES[$type->value()]);
            }
        }

        if (!$scalar || ($ofOneKind && count($named) !== 1)) {
            $diagnostics->warning(sprintf(
                '"%s" is checked on a value of any type only with one "type" of string, integer, number or boolean beside the keywords of strings and numbers; it is not checked.',
                $keyword,
            ), $at);

            return null;
        }

        return new self($schema, $excludesNull, count($named) === 1 ? self::scalar($named[0]) : new MixedType(), $named);
    }

    private static function scalar(SchemaType $type): ScalarType
    {
        switch ($type->value()) {
            case SchemaType::STRING:
                return ScalarType::string();
            case SchemaType::INTEGER:
                return ScalarType::int();
            case SchemaType::NUMBER:
                return ScalarType::float();
            default:
                return ScalarType::bool();
        }
    }

    /**
     * Whether a keyword only describes, so a schema of nothing else accepts every value.
     */
    public static function annotates(string $name): bool
    {
        return in_array($name, self::ANNOTATIONS, true) || strncmp($name, 'x-', 2) === 0;
    }

    /**
     * @param JsonValue $setting
     *
     * @return list<SchemaType>|null null when the setting names no types
     */
    private static function types($setting): ?array
    {
        $types = [];
        foreach (is_array($setting) ? $setting : [$setting] as $name) {
            $type = is_string($name) ? SchemaType::tryFrom($name) : null;
            if (!$type instanceof AbstractEnum) {
                return null;
            }

            $types[] = $type;
        }

        return $types;
    }

    /**
     * @param list<SchemaType> $types
     *
     * @return list<string>
     */
    private static function names(array $types): array
    {
        return array_map(static fn (SchemaType $named): string => $named->value(), $types);
    }

    /**
     * Null needs no room: NotNull rejects it where the schema does.
     *
     * @param list<SchemaType> $types
     */
    private static function admits(array $types, TypeModel $value): bool
    {
        if ($value instanceof EnumType) {
            $name = $value->backing()->value() === EnumBacking::INT ? SchemaType::INTEGER : SchemaType::STRING;
        } elseif ($value instanceof ScalarType) {
            $name = self::JSON_TYPES[$value->kind()];
        } else {
            return false;
        }

        $names = self::names($types);

        return in_array($name, $names, true) || ($name === SchemaType::INTEGER && in_array(SchemaType::NUMBER, $names, true));
    }
}

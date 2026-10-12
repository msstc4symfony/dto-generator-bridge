<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
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
 * The schema of `contains` or `propertyNames`, which the generator keeps as raw JSON. Only a flat one is read: its
 * keywords become constraints of one value through ConstraintBuilder. Its `type` is only checked to admit every
 * value of the PHP type, as no constraint would check a narrower one.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Subschema
{
    private const KEYWORDS = ['const', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'];

    private const ANNOTATIONS = ['title', 'description', 'examples', '$comment', 'default', 'deprecated'];

    /** The JSON type of each PHP scalar. */
    private const JSON_TYPES = ['string' => SchemaType::STRING, 'int' => SchemaType::INTEGER, 'float' => SchemaType::NUMBER, 'bool' => SchemaType::BOOLEAN];

    private Schema $schema;

    private bool $excludesNull;

    private function __construct(Schema $schema, bool $excludesNull)
    {
        $this->schema = $schema;
        $this->excludesNull = $excludesNull;
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
     * @param non-empty-string $keyword
     * @param JsonValue $value
     * @param TypeModel $type the type of each value the schema describes
     */
    public static function read(string $keyword, $value, TypeModel $type, SchemaLocation $at, Diagnostics $diagnostics): ?self
    {
        $builder = new SchemaBuilder($at->child($keyword));
        if ($value === true) {
            return new self($builder->build(), false);
        }

        if (!is_array($value) || ($value !== [] && Json::isList($value))) {
            $diagnostics->warning(sprintf('"%s" is checked only with a schema object or true; it is not checked.', $keyword), $at);

            return null;
        }

        $scalar = ($type instanceof NullableType ? $type->inner() : $type) instanceof ScalarType;
        if (!$scalar && array_filter(array_keys($value), static fn ($name): bool => !self::annotates((string) $name)) !== []) {
            $diagnostics->warning(sprintf('"%s" is checked only on values of a string, number or boolean type; it is not checked.', $keyword), $at);

            return null;
        }

        $excludesNull = false;
        foreach ($value as $name => $listed) {
            $name = (string) $name;
            $setting = Json::value($listed);
            if ($name === 'type') {
                $types = self::types($setting);
                if ($types === null || !self::admits($types, $type)) {
                    $diagnostics->warning(sprintf('"%s" is checked only when its "type" admits every element; it is not checked.', $keyword), $at);

                    return null;
                }

                $excludesNull = $excludesNull || !in_array(SchemaType::NULL, array_map(static fn (SchemaType $named): string => $named->value(), $types), true);
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

        return new self($builder->build(), $excludesNull);
    }

    private static function annotates(string $name): bool
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
     */
    private static function admits(array $types, TypeModel $type): bool
    {
        $names = array_map(static fn (SchemaType $named): string => $named->value(), $types);
        $value = $type instanceof NullableType ? $type->inner() : $type;
        if (!$value instanceof ScalarType) {
            return false;
        }

        // Null needs no room: NotNull rejects it where the schema does.
        $name = self::JSON_TYPES[$value->kind()];

        return in_array($name, $names, true) || ($name === SchemaType::INTEGER && in_array(SchemaType::NUMBER, $names, true));
    }
}

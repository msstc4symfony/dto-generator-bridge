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

    private function __construct()
    {
    }

    /**
     * @param non-empty-string $keyword
     * @param JsonValue $value
     * @param TypeModel $type the type of each value the schema describes
     */
    public static function read(string $keyword, $value, TypeModel $type, SchemaLocation $at, Diagnostics $diagnostics): ?Schema
    {
        $builder = new SchemaBuilder($at->child($keyword));
        if ($value === true) {
            return $builder->build();
        }

        if (!is_array($value) || ($value !== [] && Json::isList($value))) {
            $diagnostics->warning(sprintf('"%s" is checked only with a schema object or true; it is not checked.', $keyword), $at);

            return null;
        }

        foreach ($value as $name => $element) {
            $name = (string) $name;
            $setting = Json::value($element);
            if ($name === 'type') {
                $types = self::types($setting);
                if ($types === null || !self::admits($types, $type)) {
                    $diagnostics->warning(sprintf('"%s" is checked only when its "type" admits every element; it is not checked.', $keyword), $at);

                    return null;
                }
            } elseif ($name === 'enum') {
                if (!is_array($setting) || $setting === [] || !Json::isList($setting)) {
                    $diagnostics->warning(sprintf('"%s" is checked only with a non-empty list in "enum"; it is not checked.', $keyword), $at);

                    return null;
                }

                $enum = [];
                foreach ($setting as $listed) {
                    $enum[] = Json::value($listed);
                }

                $builder->enum($enum);
            } elseif ($name === 'format' && is_string($setting)) {
                $builder->format($setting);
            } elseif (in_array($name, self::KEYWORDS, true)) {
                $builder->keyword($name, $setting);
            } elseif (!in_array($name, self::ANNOTATIONS, true) && strncmp($name, 'x-', 2) !== 0) {
                $diagnostics->warning(sprintf(
                    '"%s" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "%s" it is not checked.',
                    $keyword,
                    $name,
                ), $at);

                return null;
            }
        }

        return $builder->build();
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
        $required = $type instanceof NullableType ? [SchemaType::NULL] : [];
        $value = $type instanceof NullableType ? $type->inner() : $type;
        if (!$value instanceof ScalarType) {
            return false;
        }

        $required[] = self::JSON_TYPES[$value->kind()];
        foreach ($required as $name) {
            if (!in_array($name, $names, true) && ($name !== SchemaType::INTEGER || !in_array(SchemaType::NUMBER, $names, true))) {
                return false;
            }
        }

        return true;
    }
}

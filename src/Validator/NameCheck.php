<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Whether a `propertyNames` schema rejects a name, by the keywords that need no validator: `type`, `pattern`,
 * `minLength`, `maxLength`, `const` and `enum`. Any other keyword (a `$ref` too), or a malformed one, decides nothing.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @internal
 */
final class NameCheck
{
    private function __construct()
    {
    }

    /**
     * @param JsonValue $schema
     */
    public static function rejects($schema, string $name): bool
    {
        if (!is_array($schema)) {
            return $schema === false;
        }

        $pattern = isset($schema['pattern']) && is_string($schema['pattern']) ? Pattern::toPcre($schema['pattern']) : null;
        // JSON Schema counts code points; a name PCRE cannot read as UTF-8 has no length to compare.
        $length = preg_match_all('/./su', $name);
        $minimum = self::limit(Json::value($schema['minLength'] ?? null));
        $maximum = self::limit(Json::value($schema['maxLength'] ?? null));
        $enum = $schema['enum'] ?? null;

        return self::rejectsByType(Json::value($schema['type'] ?? null))
            || ($pattern !== null && preg_match($pattern, $name) === 0)
            || ($length !== false && $minimum !== null && $length < $minimum)
            || ($length !== false && $maximum !== null && $length > $maximum)
            || (array_key_exists('const', $schema) && $schema['const'] !== $name)
            || (is_array($enum) && Json::isList($enum) && !in_array($name, $enum, true));
    }

    /**
     * A `type` without `string`; one that is no type name or list of them decides nothing.
     *
     * @param JsonValue $type
     */
    private static function rejectsByType($type): bool
    {
        $types = is_string($type) ? [$type] : $type;
        if (!is_array($types) || $types === [] || !Json::isList($types) || array_filter($types, 'is_string') !== $types) {
            return false;
        }

        return !in_array('string', $types, true);
    }

    /**
     * A non-negative whole number, 2.0 included as JSON Schema counts it.
     *
     * @param JsonValue $value
     *
     * @return int|float|null
     */
    private static function limit($value)
    {
        return (is_int($value) || (is_float($value) && fmod($value, 1.0) === 0.0)) && $value >= 0 ? $value : null;
    }
}

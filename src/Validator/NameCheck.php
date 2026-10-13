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

        $type = $schema['type'] ?? 'string';
        $pattern = isset($schema['pattern']) && is_string($schema['pattern']) ? Pattern::toPcre($schema['pattern']) : null;
        // JSON Schema counts code points; a name is valid UTF-8.
        $length = (int) preg_match_all('/./su', $name);
        $minimum = self::count(Json::value($schema['minLength'] ?? null));
        $maximum = self::count(Json::value($schema['maxLength'] ?? null));
        $enum = $schema['enum'] ?? null;

        return !in_array('string', is_array($type) ? $type : [$type], true)
            || ($pattern !== null && preg_match($pattern, $name) === 0)
            || ($minimum !== null && $length < $minimum)
            || ($maximum !== null && $length > $maximum)
            || (array_key_exists('const', $schema) && $schema['const'] !== $name)
            || (is_array($enum) && Json::isList($enum) && !in_array($name, $enum, true));
    }

    /**
     * @param JsonValue $value
     */
    private static function count($value): ?int
    {
        return is_int($value) || (is_float($value) && $value >= 0 && $value < PHP_INT_MAX && fmod($value, 1.0) === 0.0) ? (int) $value : null;
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Whether a `propertyNames` schema rejects a name, by the keywords that need no validator: `pattern`, `minLength`,
 * `maxLength`, `const` and `enum`. Any other keyword, or a malformed one, decides nothing.
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
        $length = mb_strlen($name, 'UTF-8');

        return ($pattern !== null && preg_match($pattern, $name) !== 1)
            || (is_int($schema['minLength'] ?? null) && $length < $schema['minLength'])
            || (is_int($schema['maxLength'] ?? null) && $length > $schema['maxLength'])
            || (array_key_exists('const', $schema) && $schema['const'] !== $name)
            || (is_array($schema['enum'] ?? null) && !in_array($name, $schema['enum'], true));
    }
}

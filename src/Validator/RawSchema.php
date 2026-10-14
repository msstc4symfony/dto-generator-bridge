<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * A schema the generator keeps as raw JSON, with its `$ref` followed: the keywords of every schema on the way, which a
 * value must all satisfy, in one object. A reference that does not resolve, a schema on the way that is not flat, or a
 * keyword two of them hold leaves the schema as written, `$ref` and all, so that it is not checked rather than checked
 * by a guess.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @internal
 */
final class RawSchema
{
    private function __construct()
    {
    }

    /**
     * @param JsonValue $value
     * @param SchemaLocation $at where the raw schema is written, which the loader keyed its reference by
     *
     * @return JsonValue
     */
    public static function dereferenced($value, SchemaLocation $at, SchemaReferences $references)
    {
        $ref = is_array($value) ? $value['$ref'] ?? null : null;
        if (!is_array($value) || !is_string($ref)) {
            return $value;
        }

        $chain = $references->follow($ref, $at);
        if ($chain === [] || $chain[count($chain) - 1]->ref() !== null || array_filter($chain, static fn (Schema $schema): bool => self::isFlat($schema)) !== $chain) {
            return $value;
        }

        $merged = $value;
        unset($merged['$ref']);
        foreach ($chain as $schema) {
            foreach (self::keywords($schema) as $name => $keyword) {
                if (array_key_exists($name, $merged)) {
                    return $value;
                }

                $merged[$name] = $keyword;
            }
        }

        return $merged;
    }

    /**
     * Whether the schema holds no keyword with an accessor of its own but those that check a value alone; the others
     * (`properties`, `allOf`…) shape the value in ways a raw schema of one value does not hold.
     */
    private static function isFlat(Schema $schema): bool
    {
        return $schema->propertyNames() === []
            && $schema->required() === []
            && !$schema->items() instanceof Schema
            && $schema->additionalProperties() === null
            && $schema->allOf() === []
            && $schema->oneOf() === []
            && $schema->anyOf() === []
            && !$schema->discriminator() instanceof Discriminator;
    }

    /**
     * The keywords of a flat schema as JSON, but for annotations and metadata, which hold for no value.
     *
     * @return array<string, JsonValue>
     */
    private static function keywords(Schema $schema): array
    {
        $keywords = [];
        $types = array_map(static fn (SchemaType $type): string => $type->value(), $schema->types());
        if ($types !== []) {
            $keywords['type'] = $types;
        }

        if ($schema->enum() !== null) {
            $keywords['enum'] = $schema->enum();
        }

        if ($schema->format() !== null) {
            $keywords['format'] = $schema->format();
        }

        foreach ($schema->keywordNames() as $name) {
            if (!Subschema::annotates($name)) {
                $keywords[$name] = $schema->keyword($name);
            }
        }

        return $keywords;
    }
}

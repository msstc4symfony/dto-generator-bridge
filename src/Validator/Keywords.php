<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The keywords of a schema that may be a `$ref`. A value must satisfy both the keywords beside the `$ref` and those of
 * the schema it ends at (JSON Schema 2020-12), so both are read; only `format` beside a `$ref` replaces the target's.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Keywords
{
    private Schema $own;

    private Schema $resolved;

    private SchemaReferences $references;

    public function __construct(Schema $own, SchemaReferences $references)
    {
        $this->own = $own;
        $this->resolved = $references->resolve($own);
        $this->references = $references;
    }

    public function resolved(): Schema
    {
        return $this->resolved;
    }

    public function has(string $name): bool
    {
        return $this->values($name) !== [];
    }

    /**
     * The distinct values of a keyword, the one beside the `$ref` first.
     *
     * @return list<JsonValue>
     */
    public function values(string $name): array
    {
        $values = [];
        foreach ([$this->own, $this->resolved] as $schema) {
            if ($schema->hasKeyword($name) && !in_array($schema->keyword($name), $values, true)) {
                $values[] = $schema->keyword($name);
            }
        }

        return $values;
    }

    public function format(): ?string
    {
        return $this->own->format() ?? $this->resolved->format();
    }

    /**
     * The values both schemas allow.
     *
     * @return list<JsonValue>|null
     */
    public function enum(): ?array
    {
        $own = $this->own->enum();
        $resolved = $this->resolved->enum();
        if ($own === null) {
            return $resolved;
        }

        if ($resolved === null) {
            return $own;
        }

        $both = [];
        foreach ($own as $value) {
            if (in_array($value, $resolved, true)) {
                $both[] = $value;
            }
        }

        return $both;
    }

    public function narrowsReferencedEnum(): bool
    {
        return $this->own->ref() !== null && $this->own->enum() !== null;
    }

    /**
     * Whether the value becomes a generated class: an object with properties, or a composition with such a branch. The
     * generator turns a property-less object into an array; a date-time is a class too, but a string in the schema.
     */
    public function describesObject(): bool
    {
        return $this->isObject($this->resolved, []);
    }

    /**
     * @param list<string> $seen locations of the compositions on the way, against `$ref` cycles
     */
    private function isObject(Schema $schema, array $seen): bool
    {
        if ($schema->propertyNames() !== []) {
            return true;
        }

        $seen[] = $schema->location()->toString();
        foreach (array_merge($schema->allOf(), $schema->oneOf(), $schema->anyOf()) as $branch) {
            $resolved = $this->references->resolve($branch);
            if (!in_array($resolved->location()->toString(), $seen, true) && $this->isObject($resolved, $seen)) {
                return true;
            }
        }

        return false;
    }
}

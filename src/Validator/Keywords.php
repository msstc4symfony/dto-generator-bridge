<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The keywords a value must satisfy (JSON Schema 2020-12): those of its schema, of every schema its `$ref` chain passes
 * through, and of its `allOf` branches. Only `format` is taken from the nearest schema that has one.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Keywords
{
    /** @var list<Schema> nearest first */
    private array $schemas;

    private Schema $resolved;

    private SchemaReferences $references;

    public function __construct(Schema $own, SchemaReferences $references)
    {
        $this->references = $references;
        $this->schemas = $this->collect($own, []);
        $this->resolved = $this->unwrap($references->resolve($own), []);
    }

    /**
     * The schema that describes the value: the end of the `$ref` chain, through an `allOf` with a single branch (an
     * idiom for adding a description or nullability to a reference), as the generator types it.
     */
    public function resolved(): Schema
    {
        return $this->resolved;
    }

    public function has(string $name): bool
    {
        return $this->values($name) !== [];
    }

    /**
     * The distinct values of a keyword, the nearest first.
     *
     * @return list<JsonValue>
     */
    public function values(string $name): array
    {
        $values = [];
        foreach ($this->schemas as $schema) {
            if ($schema->hasKeyword($name) && !in_array($schema->keyword($name), $values, true)) {
                $values[] = $schema->keyword($name);
            }
        }

        return $values;
    }

    public function format(): ?string
    {
        foreach ($this->schemas as $schema) {
            if ($schema->format() !== null) {
                return $schema->format();
            }
        }

        return null;
    }

    /**
     * The values every schema with an `enum` allows; null when none has one.
     *
     * @return list<JsonValue>|null
     */
    public function enum(): ?array
    {
        $allowed = null;
        foreach ($this->enums() as $enum) {
            $allowed = $allowed === null ? $enum : $this->common($allowed, $enum);
        }

        return $allowed;
    }

    /**
     * Whether more than one schema lists the values, so that one narrows the other.
     */
    public function narrowsEnum(): bool
    {
        return count($this->enums()) > 1;
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
     * @param list<string> $seen locations already collected, against `$ref` and `allOf` cycles
     *
     * @return list<Schema>
     */
    private function collect(Schema $schema, array $seen): array
    {
        $schemas = [];
        foreach ($this->references->chain($schema) as $link) {
            $location = $link->location()->toString();
            // The rest of the chain was collected where this link was.
            if (in_array($location, $seen, true)) {
                return $schemas;
            }

            $seen[] = $location;
            $schemas[] = $link;
            // A schema reached through two branches is read twice, which changes nothing: values() drops repeats.
            foreach ($link->allOf() as $branch) {
                $schemas = array_merge($schemas, $this->collect($branch, $seen));
            }
        }

        return $schemas;
    }

    /**
     * @param list<string> $seen
     */
    private function unwrap(Schema $schema, array $seen): Schema
    {
        $branches = $schema->allOf();
        $location = $schema->location()->toString();
        if (count($branches) !== 1 || $schema->types() !== [] || $schema->propertyNames() !== [] || in_array($location, $seen, true)) {
            return $schema;
        }

        $seen[] = $location;

        return $this->unwrap($this->references->resolve($branches[0]), $seen);
    }

    /**
     * @return list<list<JsonValue>>
     */
    private function enums(): array
    {
        $enums = [];
        foreach ($this->schemas as $schema) {
            $enum = $schema->enum();
            if ($enum !== null) {
                $enums[] = $enum;
            }
        }

        return $enums;
    }

    /**
     * @param list<JsonValue> $allowed
     * @param list<JsonValue> $enum
     *
     * @return list<JsonValue>
     */
    private function common(array $allowed, array $enum): array
    {
        $common = [];
        foreach ($allowed as $value) {
            if (in_array($value, $enum, true)) {
                $common[] = $value;
            }
        }

        return $common;
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

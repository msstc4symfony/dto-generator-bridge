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
    private array $schemas = [];

    /** @var list<string> locations of */
    private array $collected = [];

    private Schema $resolved;

    private SchemaReferences $references;

    public function __construct(Schema $own, SchemaReferences $references)
    {
        $this->references = $references;
        $this->collect($own);
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
        return $this->formats()[0] ?? null;
    }

    /**
     * The distinct formats, the nearest first.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        $formats = [];
        foreach ($this->schemas as $schema) {
            $format = $schema->format();
            if ($format !== null && !in_array($format, $formats, true)) {
                $formats[] = $format;
            }
        }

        return $formats;
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
        $allowed = $this->enum() ?? [];
        foreach ($this->enums() as $enum) {
            if (count($allowed) < count($enum)) {
                return true;
            }
        }

        return false;
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
     * Every location is read once: values() would drop repeats anyway, and a link already read had the rest of its
     * chain read with it.
     */
    private function collect(Schema $schema): void
    {
        foreach ($this->references->chain($schema) as $link) {
            $location = $link->location()->toString();
            if (in_array($location, $this->collected, true)) {
                return;
            }

            $this->collected[] = $location;
            $this->schemas[] = $link;
            foreach ($link->allOf() as $branch) {
                $this->collect($branch);
            }
        }
    }

    /**
     * The generator types a value through `allOf` when exactly one branch says what the value is; the others only
     * constrain it.
     *
     * @param list<string> $seen
     */
    private function unwrap(Schema $schema, array $seen): Schema
    {
        $location = $schema->location()->toString();
        $typed = array_values(array_filter($schema->allOf(), fn (Schema $branch): bool => $this->isTyped($branch)));
        if (count($typed) !== 1 || $schema->propertyNames() !== [] || $schema->oneOf() !== [] || $schema->anyOf() !== [] || in_array($location, $seen, true)) {
            return $schema;
        }

        $seen[] = $location;

        return $this->unwrap($this->references->resolve($typed[0]), $seen);
    }

    private function isTyped(Schema $branch): bool
    {
        return $branch->ref() !== null
            || $branch->nonNullTypes() !== []
            || $branch->enum() !== null
            || $branch->allOf() !== [] || $branch->oneOf() !== [] || $branch->anyOf() !== []
            || $branch->propertyNames() !== []
            || $branch->extensions()->has('x-php-type');
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

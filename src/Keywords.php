<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The keywords a value must satisfy (JSON Schema 2020-12): those of its schema, of every schema its `$ref` chain passes
 * through, and of its `allOf` branches. Values compare as JSON does: 1 and 1.0 are the same number.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Keywords
{
    /** @var list<Schema> nearest first */
    private array $schemas = [];

    /** @var list<string> locations of the schemas already collected, each read once */
    private array $collected = [];

    /** @var list<string> compositions already searched for an object, against `$ref` cycles and repeated work */
    private array $explored = [];

    private Schema $resolved;

    private SchemaReferences $references;

    public function __construct(Schema $own, SchemaReferences $references)
    {
        $this->references = $references;
        $this->collect($own);
        $this->resolved = $this->unwrap($references->resolve($own), []);
    }

    /**
     * The schema that describes the value, as the generator types it: the end of the `$ref` chain, through the one
     * typed branch of an `allOf` (an idiom for annotating or constraining a reference).
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
            if ($schema->hasKeyword($name) && !$this->contains($values, $schema->keyword($name))) {
                $values[] = $schema->keyword($name);
            }
        }

        return $values;
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
     * Whether one `enum` allows fewer values than another.
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
        $this->explored = [];

        return $this->isObject($this->resolved);
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
     * Follows TypeMapper::bareType() of the generator: `x-php-type`, a class, or a typed union keeps the schema;
     * otherwise exactly one typed `allOf` branch gives the type, and the other branches only constrain it.
     *
     * @param list<string> $seen
     */
    private function unwrap(Schema $schema, array $seen): Schema
    {
        $location = $schema->location()->toString();
        if (
            $schema->extensions()->has('x-php-type')
            || $schema->propertyNames() !== []
            || $this->typed(array_merge($schema->oneOf(), $schema->anyOf())) !== []
            || in_array($location, $seen, true)
        ) {
            return $schema;
        }

        $typed = $this->typed($schema->allOf());
        if (count($typed) !== 1) {
            return $schema;
        }

        $seen[] = $location;

        return $this->unwrap($this->references->resolve($typed[0]), $seen);
    }

    /**
     * @param list<Schema> $members
     *
     * @return list<Schema> the members that say what the value is, as TypeMapper::typed() of the generator decides
     */
    private function typed(array $members): array
    {
        return array_values(array_filter(
            $members,
            static fn (Schema $member): bool => $member->ref() !== null
                || $member->nonNullTypes() !== []
                || $member->enum() !== null
                || $member->allOf() !== [] || $member->oneOf() !== [] || $member->anyOf() !== []
                || $member->propertyNames() !== []
                || $member->extensions()->has('x-php-type'),
        ));
    }

    /**
     * @return list<list<JsonValue>> each without repeated values
     */
    private function enums(): array
    {
        $enums = [];
        foreach ($this->schemas as $schema) {
            $enum = $schema->enum();
            if ($enum !== null) {
                $enums[] = $this->common($enum, $enum);
            }
        }

        return $enums;
    }

    /**
     * @param list<JsonValue> $allowed
     * @param list<JsonValue> $enum
     *
     * @return list<JsonValue> the distinct values of $allowed that $enum lists too
     */
    private function common(array $allowed, array $enum): array
    {
        $common = [];
        foreach ($allowed as $value) {
            if ($this->contains($enum, $value) && !$this->contains($common, $value)) {
                $common[] = $value;
            }
        }

        return $common;
    }

    /**
     * @param list<JsonValue> $values
     * @param JsonValue $value
     */
    private function contains(array $values, $value): bool
    {
        foreach ($values as $listed) {
            if ($this->same($listed, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param JsonValue $a
     * @param JsonValue $b
     */
    private function same($a, $b): bool
    {
        $numbers = (is_int($a) || is_float($a)) && (is_int($b) || is_float($b));
        if (!$numbers || (is_int($a) && is_int($b))) {
            return $a === $b;
        }

        return (float) $a === (float) $b;
    }

    private function isObject(Schema $schema): bool
    {
        if ($schema->propertyNames() !== []) {
            return true;
        }

        $this->explored[] = $schema->location()->toString();
        foreach (array_merge($schema->allOf(), $schema->oneOf(), $schema->anyOf()) as $branch) {
            $resolved = $this->references->resolve($branch);
            if (!in_array($resolved->location()->toString(), $this->explored, true) && $this->isObject($resolved)) {
                return true;
            }
        }

        return false;
    }
}

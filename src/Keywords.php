<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The keywords a value must satisfy (JSON Schema 2020-12): those of its schema, of every schema its `$ref` chain passes
 * through, of its `allOf` branches, and of the one member of a `oneOf`/`anyOf` that leaves only null aside (a value
 * other than null has to satisfy it). Values compare as JSON does, numbers by their exact value: 1 and 1.0 are the same
 * number, 2^53 + 1 and the float nearest to it are not.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @internal
 */
final class Keywords
{
    /** The floats that bound the range of int: PHP_INT_MAX itself rounds up to the upper one, which int lacks. */
    private const MIN_INT_FLOAT = -9.2233720368547758E18;

    private const MAX_INT_FLOAT = 9.2233720368547758E18;

    /** @var list<Schema> nearest first */
    private array $schemas = [];

    /** @var list<string> locations of the schemas already collected, each read once */
    private array $collected = [];

    /** @var list<string> compositions already searched for an object, against `$ref` cycles and repeated work */
    private array $explored = [];

    private Schema $resolved;

    private SchemaReferences $references;

    /**
     * @param list<string> $stops locations to read nothing from, as places another class owns
     */
    public function __construct(Schema $own, SchemaReferences $references, array $stops = [])
    {
        $this->references = $references;
        $this->collected = $stops;
        $this->collect($own);
        $this->resolved = $this->unwrap($references->resolve($own), []);
    }

    /**
     * The schema that describes the value, as the generator types it: the end of the `$ref` chain, through the one
     * typed branch of an `allOf` (an idiom for annotating or constraining a reference) or the one typed member of a
     * `oneOf`/`anyOf` besides null.
     */
    public function resolved(): Schema
    {
        return $this->resolved;
    }

    /**
     * Every place the keywords come from: the schema, the links of its `$ref` chain and its `allOf` branches.
     *
     * @return list<Schema> nearest first
     */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * The `oneOf`/`anyOf` members the keywords leave out: those of a union with more than one member besides null, which
     * the generator types as several values.
     *
     * @return list<Schema>
     */
    public function unreachedMembers(): array
    {
        $members = [];
        foreach ($this->schemas as $schema) {
            if (!$this->soleValueMember($schema) instanceof Schema) {
                array_push($members, ...$schema->oneOf(), ...$schema->anyOf());
            }
        }

        return $members;
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
            $key = $schema->hasKeyword($name) ? $this->key($schema->keyword($name)) : null;
            if ($key !== null && !array_key_exists($key, $values)) {
                $values[$key] = $schema->keyword($name);
            }
        }

        return array_values($values);
    }

    /**
     * The distinct formats by the name the generator reads them as, the nearest first.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        $formats = [];
        foreach ($this->schemas as $schema) {
            $format = $schema->normalizedFormat();
            if ($format !== null && !in_array($format, $formats, true)) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    /**
     * The values every schema with an `enum` allows; null when none has one. An enum its own `type` admits none of is
     * left out, as the generator ignores it.
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

            $member = $this->soleValueMember($link);
            if ($member instanceof Schema) {
                $this->collect($member);
            }
        }
    }

    /**
     * The one `oneOf`/`anyOf` member that is not just null; TypeMapper::union() of the generator types the value by it.
     */
    private function soleValueMember(Schema $schema): ?Schema
    {
        $members = array_values(array_filter(
            array_merge($schema->oneOf(), $schema->anyOf()),
            static fn (Schema $member): bool => $member->ref() !== null
                || !$member->isNullable()
                || $member->nonNullTypes() !== []
                || $member->allOf() !== [] || $member->oneOf() !== [] || $member->anyOf() !== [],
        ));

        return count($members) === 1 ? $members[0] : null;
    }

    /**
     * Follows TypeMapper::bareType() of the generator: `x-php-type` or a class keeps the schema; a typed union gives the
     * type of its one member besides null, or keeps the schema; otherwise exactly one typed `allOf` branch gives the
     * type, and the other branches only constrain it. A branch that only says `type: object` beside another adds
     * nothing (generator 1.14).
     *
     * @param list<string> $seen
     */
    private function unwrap(Schema $schema, array $seen): Schema
    {
        $location = $schema->location()->toString();
        if ($schema->extensions()->has('x-php-type') || $schema->propertyNames() !== [] || in_array($location, $seen, true)) {
            return $schema;
        }

        $seen[] = $location;
        $union = $this->typed(array_merge($schema->oneOf(), $schema->anyOf()));
        if ($union !== []) {
            $member = $this->soleValueMember($schema);

            // A member that only constrains (a pattern) leaves the type to the schema itself, as in the generator.
            return $member instanceof Schema && $this->typed([$member]) !== [] ? $this->unwrap($this->references->resolve($member), $seen) : $schema;
        }

        $typed = $this->typed($schema->allOf());
        $specific = array_values(array_filter($typed, static fn (Schema $member): bool => !self::isPlainObject($member)));
        $typed = $specific === [] ? $typed : $specific;
        if (count($typed) !== 1) {
            return $schema;
        }

        return $this->unwrap($this->references->resolve($typed[0]), $seen);
    }

    /**
     * @param list<Schema> $members
     *
     * @return array<int, Schema> the members that say what the value is, as TypeMapper::typed() of the generator decides
     */
    private function typed(array $members): array
    {
        return array_filter(
            $members,
            static fn (Schema $member): bool => $member->ref() !== null
                || $member->nonNullTypes() !== []
                || $member->enum() !== null
                || $member->allOf() !== [] || $member->oneOf() !== [] || $member->anyOf() !== []
                || $member->propertyNames() !== []
                || $member->extensions()->has('x-php-type'),
        );
    }

    /**
     * @return list<list<JsonValue>> each without repeated values
     */
    private function enums(): array
    {
        $enums = [];
        foreach ($this->schemas as $schema) {
            $enum = $schema->enum();
            if ($enum !== null && !$this->contradictsItsEnum($schema)) {
                $enums[] = $this->common($enum, $enum);
            }
        }

        return $enums;
    }

    /**
     * `type: object` and nothing that types or constrains its members, as SchemaShape::isPlainObject() of the generator.
     */
    private static function isPlainObject(Schema $schema): bool
    {
        $additional = $schema->additionalProperties();

        return $schema->ref() === null
            && !$schema->extensions()->has('x-php-type')
            && $schema->nonNullTypes() === [SchemaType::from(SchemaType::OBJECT)]
            && $schema->propertyNames() === []
            && $schema->required() === []
            && $schema->allOf() === [] && $schema->oneOf() === [] && $schema->anyOf() === []
            && $schema->enum() === null
            && !$schema->hasKeyword('const')
            && !$schema->hasKeyword('patternProperties')
            && !$schema->discriminator() instanceof Discriminator
            && ($additional === null || $additional === true);
    }

    /**
     * A `type` that admits none of the string and integer values of the enum, which the generator ignores (1.14), as
     * SchemaShape::contradictsItsEnum() does.
     */
    private function contradictsItsEnum(Schema $schema): bool
    {
        $declared = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());
        $values = array_filter($schema->enum() ?? [], static fn ($value): bool => is_int($value) || is_string($value));
        if ($declared === [] || $values === []) {
            return false;
        }

        $strings = in_array(SchemaType::STRING, $declared, true);
        $numbers = in_array(SchemaType::INTEGER, $declared, true) || in_array(SchemaType::NUMBER, $declared, true);
        foreach ($values as $value) {
            if (is_string($value) ? $strings : $numbers) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<JsonValue> $allowed
     * @param list<JsonValue> $enum
     *
     * @return list<JsonValue> the distinct values of $allowed that $enum lists too
     */
    private function common(array $allowed, array $enum): array
    {
        // Large enums (countries, currencies) meet each other, so they are matched by key rather than pairwise.
        $listed = [];
        foreach ($enum as $value) {
            $key = $this->key($value);
            $listed[$key] = $key;
        }

        $common = [];
        foreach ($allowed as $value) {
            $key = $this->key($value);
            if (isset($listed[$key]) && !array_key_exists($key, $common)) {
                $common[$key] = $value;
            }
        }

        return array_values($common);
    }

    /**
     * A key two values share when JSON holds them equal: numbers by their exact value, a whole one as an integer and
     * any other by its bits, as serialize() would round it to serialize_precision; anything else serialized.
     *
     * @param JsonValue $value
     */
    private function key($value): string
    {
        $value = $this->exact($value);

        return is_float($value) ? bin2hex(pack('E', $value)) : serialize($value);
    }

    /**
     * The value with a whole float within the range of int as that int, -0.0 among them; in arrays only -0.0 becomes
     * 0.0, as serialize() would tell them apart although === does not.
     *
     * @param JsonValue $value
     *
     * @return JsonValue
     */
    private function exact($value)
    {
        if (is_array($value)) {
            return array_map(fn ($member) => is_array($member) ? $this->exact($member) : ($member === 0.0 ? abs($member) : $member), $value);
        }

        if (is_float($value) && $value >= self::MIN_INT_FLOAT && $value < self::MAX_INT_FLOAT && fmod($value, 1.0) === 0.0) {
            return (int) $value;
        }

        return $value;
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

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The keywords of a schema that may be a `$ref`: those written beside the `$ref` win over the referenced schema's.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Keywords
{
    private Schema $own;

    private Schema $resolved;

    public function __construct(Schema $own, Schema $resolved)
    {
        $this->own = $own;
        $this->resolved = $resolved;
    }

    public function has(string $name): bool
    {
        return $this->own->hasKeyword($name) || $this->resolved->hasKeyword($name);
    }

    /**
     * @return JsonValue
     */
    public function get(string $name)
    {
        return $this->own->hasKeyword($name) ? $this->own->keyword($name) : ($this->resolved->hasKeyword($name) ? $this->resolved->keyword($name) : null);
    }

    /**
     * @return int|float|null
     */
    public function number(string $name)
    {
        $value = $this->get($name);

        return is_int($value) || is_float($value) ? $value : null;
    }

    public function format(): ?string
    {
        return $this->own->format() ?? $this->resolved->format();
    }

    /**
     * @return list<JsonValue>|null
     */
    public function enum(): ?array
    {
        return $this->own->enum() ?? $this->resolved->enum();
    }

    /**
     * Whether the value becomes a generated class: the generator turns an object with properties or a composition into
     * one, and a property-less object into an array; a date-time is a class too, but a string in the schema.
     */
    public function describesObject(): bool
    {
        $schema = $this->resolved;

        return $schema->propertyNames() !== [] || $schema->allOf() !== [] || $schema->oneOf() !== [] || $schema->anyOf() !== [];
    }
}

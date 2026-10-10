<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Runtime;

/**
 * Where a class keeps its undeclared properties and which keys it declares, as AdditionalPropertiesNormalizer reads
 * them for one format.
 *
 * @internal
 */
final class ClassLayout
{
    private string $property;

    private string $key;

    /** @var array<string, true> */
    private array $declared;

    /**
     * @param string $property the PHP name of the map
     * @param string $key the name the wrapped normalizer writes the map under
     * @param array<string, true> $declared the keys the class declares
     */
    public function __construct(string $property, string $key, array $declared)
    {
        $this->property = $property;
        $this->key = $key;
        $this->declared = $declared;
    }

    public function property(): string
    {
        return $this->property;
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, true>
     */
    public function declared(): array
    {
        return $this->declared;
    }

    public function declares(string $key): bool
    {
        return $this->declared[$key] ?? false;
    }
}

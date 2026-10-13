<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The properties a class has, its own and those of the ancestors the run generates, by wire name; the runtime's
 * constraints read inherited properties too. A keyword the nearest ancestor reaches from its own schema belongs to it:
 * it checks the keyword, and Symfony gives the class its constraints too.
 *
 * @internal
 */
final class ClassProperties
{
    /** @var array<array-key, PropertyModel> by wire name, a numeric one as an int: its own, then the nearest ancestor's */
    private array $properties;

    /** @var int<0, max> how many of the properties are the class's own */
    private int $own;

    /** @var list<string> the locations of the places the nearest ancestor's keywords reach */
    private array $inherited = [];

    /**
     * @param list<ClassModel> $ancestors nearest first
     * @param Keywords $keywords those of the class's own schema
     */
    public function __construct(ClassModel $class, array $ancestors, Keywords $keywords, SchemaReferences $references)
    {
        $this->properties = $this->declared($class);
        $this->own = count($this->properties);
        foreach ($ancestors as $ancestor) {
            $this->properties += $this->declared($ancestor);
        }

        $parent = $ancestors === [] ? null : $ancestors[0]->source()->toString();
        foreach ($keywords->schemas() as $place) {
            if ($place->location()->toString() === $parent) {
                // A union of the parent may lead back to the class, whose own places stay its own.
                foreach ((new Keywords($place, $references, [$class->source()->toString()]))->schemas() as $reached) {
                    $this->inherited[] = $reached->location()->toString();
                }
            }
        }
    }

    /**
     * The wire names of the properties, its own first.
     *
     * @param bool $inherited whether those of the ancestors count too
     *
     * @return list<string>
     */
    public function names(bool $inherited): array
    {
        $names = array_map('strval', array_keys($this->properties));

        return $inherited ? $names : array_slice($names, 0, $this->own);
    }

    public function find(string $wireName): ?PropertyModel
    {
        return $this->properties[$wireName] ?? null;
    }

    /**
     * Whether the nearest ancestor reaches the place from its own schema, so its class checks the place's keywords.
     */
    public function ofAncestor(Schema $place): bool
    {
        return in_array($place->location()->toString(), $this->inherited, true);
    }

    /**
     * @return array<array-key, PropertyModel>
     */
    private function declared(ClassModel $class): array
    {
        $properties = [];
        foreach ($class->properties() as $property) {
            if (!$property->isAdditionalProperties()) {
                $properties[$property->wireName()] = $property;
            }
        }

        return $properties;
    }
}

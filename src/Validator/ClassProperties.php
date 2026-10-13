<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * The properties a class has, its own and those of the ancestors the run generates, by wire name; the runtime's
 * constraints read inherited properties too. A keyword in an ancestor's schema belongs to the ancestor, which checks it.
 *
 * @internal
 */
final class ClassProperties
{
    /** @var array<array-key, PropertyModel> by wire name, a numeric one as an int: its own, then the nearest ancestor's */
    private array $properties;

    /** @var list<string> */
    private array $ancestorLocations = [];

    /**
     * @param list<ClassModel> $ancestors nearest first
     */
    public function __construct(ClassModel $class, array $ancestors)
    {
        $this->properties = $this->declared($class);
        foreach ($ancestors as $ancestor) {
            $this->properties += $this->declared($ancestor);
            $this->ancestorLocations[] = $ancestor->source()->toString();
        }
    }

    public function find(string $wireName): ?PropertyModel
    {
        return $this->properties[$wireName] ?? null;
    }

    /**
     * Whether a place lies in the schema of an ancestor, whose class checks its keywords.
     */
    public function ofAncestor(Schema $place): bool
    {
        $at = $place->location()->toString();
        foreach ($this->ancestorLocations as $location) {
            if ($at === $location || strncmp($at, $location . '/', strlen($location) + 1) === 0) {
                return true;
            }
        }

        return false;
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

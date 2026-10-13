<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * What the properties of a checked class need from its schema: `propertyNames` and `patternProperties` are keywords of
 * the class, checked on its properties, whose contexts do not carry the class's schema.
 */
final class ClassKeywords
{
    private Keywords $keywords;

    private SchemaLocation $location;

    /** @var list<non-empty-string> */
    private array $groups;

    /** @var list<PatternSchema> */
    private array $patterns;

    /**
     * @param list<non-empty-string> $groups the groups of the class
     * @param list<PatternSchema> $patterns
     */
    public function __construct(Keywords $keywords, SchemaLocation $location, array $groups, array $patterns)
    {
        $this->keywords = $keywords;
        $this->location = $location;
        $this->groups = $groups;
        $this->patterns = $patterns;
    }

    public function keywords(): Keywords
    {
        return $this->keywords;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }

    /**
     * @return list<non-empty-string>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @return list<PatternSchema>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }
}

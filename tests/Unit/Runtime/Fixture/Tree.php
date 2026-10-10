<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties;

/**
 * Public properties, as the generator writes them for mutable DTOs with public-properties accessors.
 */
final class Tree
{
    public string $name = '';

    /**
     * @var list<Tree>
     */
    public array $children = [];

    /**
     * @var array<string, Tree>
     */
    #[AdditionalProperties]
    public array $additionalProperties = [];
}

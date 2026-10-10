<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties;

final class Circle implements Shape, Coloured
{
    public int $radius = 0;

    /**
     * @var array<string, string>
     */
    #[AdditionalProperties]
    public array $additionalProperties = [];
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties;

final class Car implements Vehicle, BodyStyled
{
    public int $wheels = 0;

    /**
     * @var array<string, string>
     */
    #[AdditionalProperties]
    public array $additionalProperties = [];
}

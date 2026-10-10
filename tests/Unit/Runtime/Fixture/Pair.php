<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties;

/**
 * Symfony writes the discriminator of Primary, reached through Secondary, not that of Another.
 */
final class Pair implements Secondary, Another
{
    /**
     * @var array<string, string>
     */
    #[AdditionalProperties]
    public array $additionalProperties = [];
}

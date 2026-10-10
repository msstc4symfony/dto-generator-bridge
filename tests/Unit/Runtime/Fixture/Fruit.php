<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Symfony\Component\Serializer\Attribute as Serializer;

/**
 * The default type needs symfony\/serializer 7.3.
 */
#[Serializer\DiscriminatorMap(typeProperty: 'fruit', mapping: ['apple' => Apple::class], defaultType: 'apple')]
interface Fruit
{
}

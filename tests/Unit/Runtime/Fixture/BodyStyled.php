<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Symfony\Component\Serializer\Attribute as Serializer;

/**
 * Declared after Vehicle on Car, so Symfony writes Vehicle's discriminator, not this one.
 */
#[Serializer\DiscriminatorMap(typeProperty: 'bodyStyle', mapping: ['car' => Car::class])]
interface BodyStyled
{
}

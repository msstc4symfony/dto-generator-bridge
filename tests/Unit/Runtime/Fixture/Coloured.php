<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Symfony\Component\Serializer\Attribute as Serializer;

/**
 * Declared after Shape on Circle, so Symfony writes Shape's discriminator, not this one.
 */
#[Serializer\DiscriminatorMap(typeProperty: 'colour', mapping: ['circle' => Circle::class])]
interface Coloured
{
}

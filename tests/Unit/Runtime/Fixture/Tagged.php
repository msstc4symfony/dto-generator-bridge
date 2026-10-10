<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Symfony\Component\Serializer\Attribute as Serializer;

/**
 * A second discriminator that Symfony never writes for Cat: its parent's comes first.
 */
#[Serializer\DiscriminatorMap(typeProperty: 'tag', mapping: ['cat' => Cat::class])]
interface Tagged
{
}

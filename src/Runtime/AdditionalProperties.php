<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Runtime;

use Attribute;

/**
 * Marks the map that AdditionalPropertiesNormalizer spreads over the keys of its object. The generator writes it on
 * $additionalProperties when extensionConfig.symfony.additionalProperties is spread.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class AdditionalProperties
{
}

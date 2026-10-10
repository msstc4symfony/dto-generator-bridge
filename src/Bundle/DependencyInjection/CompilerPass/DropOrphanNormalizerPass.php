<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalPropertiesNormalizer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * FrameworkBundle drops the object normalizer when property access is off, after the bridge's extension registered
 * the normalizer that wraps it; the serializer would then take a normalizer with a broken reference.
 */
final class DropOrphanNormalizerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('serializer.normalizer.object')) {
            $container->removeDefinition(AdditionalPropertiesNormalizer::class);
        }
    }
}

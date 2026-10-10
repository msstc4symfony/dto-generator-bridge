<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection\CompilerPass\DropOrphanNormalizerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The `dto-generator:generate` command, the optional warmup check and the normalizer of `$additionalProperties` in a
 * Symfony application (bridge spec §7). The generator itself needs no bundle: the Composer plugin and the core's CLI
 * work without it.
 */
final class DtoGeneratorBundle extends Bundle
{
    /** Ahead of SerializerPass, which FrameworkBundle adds at the default priority 0. */
    private const BEFORE_SERIALIZER_PASS = 1;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new DropOrphanNormalizerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, self::BEFORE_SERIALIZER_PASS);
    }
}

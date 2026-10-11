<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\Build\ClassMapLoader;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection\CompilerPass\BuildDtosPass;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\Bundle\DependencyInjection\CompilerPass\DropOrphanNormalizerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
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

    /** Ahead of every pass of FrameworkBundle, which reads controller signatures at the default priority 0 and later. */
    private const BEFORE_CONTROLLER_PASSES = 100;

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DropOrphanNormalizerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, self::BEFORE_SERIALIZER_PASS);
        $container->addCompilerPass(new BuildDtosPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, self::BEFORE_CONTROLLER_PASSES);
    }

    public function boot(): void
    {
        // "??": Symfony 6.1+ leaves the typed property uninitialized until the kernel sets the container.
        $container = $this->container ?? null;
        if (!$container instanceof ContainerInterface || !$container->hasParameter(BuildDtosPass::CLASSES)) {
            return;
        }

        $parameter = $container->getParameter(BuildDtosPass::CLASSES);
        $classes = [];
        foreach (is_array($parameter) ? $parameter : [] as $class => $file) {
            if (is_string($class) && is_string($file)) {
                $classes[$class] = $file;
            }
        }

        ClassMapLoader::add($classes);
    }
}

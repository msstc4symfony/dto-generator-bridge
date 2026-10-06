<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\CacheWarmer\GenerationCheckWarmer;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\Command\GenerateCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the services in code: config files load differently across Symfony 5.4 to 8.
 */
final class DtoGeneratorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->register(GenerateCommand::class, GenerateCommand::class)
            ->setArguments([$config['config'], '%kernel.project_dir%'])
            ->addTag('console.command', ['command' => GenerateCommand::NAME])
        ;

        if ($config['check_on_warmup'] !== true) {
            return;
        }

        $container->register(GenerationCheckWarmer::class, GenerationCheckWarmer::class)
            ->setArguments([$config['config'], new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->addTag('kernel.cache_warmer')
        ;
    }
}

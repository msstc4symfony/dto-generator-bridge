<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\CacheWarmer\GenerationCheckWarmer;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\Command\GenerateCommand;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalPropertiesNormalizer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use WeakMap;

/**
 * Registers the services in code: config files load differently across Symfony 5.4 to 8.
 */
final class DtoGeneratorExtension extends Extension
{
    // `list` shows it without building the command; the command itself copies the core's.
    private const DESCRIPTION = 'Generates DTO classes from the OpenAPI schemas named in the config.';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        // PHP 7.4 cannot load the normalizer, whose signatures use "mixed". WeakMap came with PHP 8.0 and no polyfill
        // declares it (symfony/polyfill-php80 does declare Attribute); a version comparison Rector's PHP 7.4 set would
        // fold to false, and Infection would mutate its number. Without the serializer nothing uses the private service
        // and the container drops it with its references.
        if (class_exists(WeakMap::class)) {
            $container->register(AdditionalPropertiesNormalizer::class, AdditionalPropertiesNormalizer::class)
                ->setArguments([
                    new Reference('serializer.normalizer.object'),
                    new Reference('serializer.mapping.class_metadata_factory'),
                    new Reference('serializer.name_converter.metadata_aware'),
                ])
                ->addTag('serializer.normalizer')
            ;
        }

        $container->register(GenerateCommand::class, GenerateCommand::class)
            ->setArguments([$config['config'], '%kernel.project_dir%'])
            ->addTag('console.command', ['command' => GenerateCommand::NAME, 'description' => self::DESCRIPTION])
        ;

        if ($config['check_on_warmup'] !== true) {
            return;
        }

        $container->register(GenerationCheckWarmer::class, GenerationCheckWarmer::class)
            ->setArguments([$config['config'], '%kernel.project_dir%', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->addTag('kernel.cache_warmer')
        ;
    }
}

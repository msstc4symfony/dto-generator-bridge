<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The `dto_generator` section.
 */
final class Configuration implements ConfigurationInterface
{
    public const COMMITTED = 'committed';

    public const BUILD = 'build';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('dto_generator');
        $tree->getRootNode()
            ->children()
                ->scalarNode('config')
                    ->info('The generator config file; a relative path is resolved against the project directory.')
                    ->defaultValue('%kernel.project_dir%/dto-generator.yaml')
                    ->cannotBeEmpty()
                ->end()
                ->enumNode('mode')
                    ->info('committed: the DTOs live in the sources; build: they are generated into the build directory with the container.')
                    ->values([self::COMMITTED, self::BUILD])
                    ->defaultValue(self::COMMITTED)
                ->end()
                ->booleanNode('check_on_warmup')
                    ->info('Log a warning on cache warmup when the generated DTOs are out of date; never writes them.')
                    ->defaultFalse()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['mode'] === self::BUILD && $config['check_on_warmup'] === true)
                ->thenInvalid('check_on_warmup compares the DTOs in the sources with the specifications; with mode: build there are none.')
            ->end()
        ;

        return $tree;
    }
}

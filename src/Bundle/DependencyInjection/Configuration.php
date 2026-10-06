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
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('dto_generator');
        $tree->getRootNode()
            ->children()
                ->scalarNode('config')
                    ->info('The generator config, as dto-generator generate --config takes it.')
                    ->defaultValue('%kernel.project_dir%/dto-generator.yaml')
                    ->cannotBeEmpty()
                ->end()
                ->booleanNode('check_on_warmup')
                    ->info('Log a warning on cache warmup when the generated DTOs are out of date; never writes them.')
                    ->defaultFalse()
                ->end()
            ->end()
        ;

        return $tree;
    }
}

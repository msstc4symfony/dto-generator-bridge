<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\CacheWarmer\GenerationCheckWarmer;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\Command\GenerateCommand;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection\CompilerPass\BuildDtosPass;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\Bundle\DependencyInjection\Normalizers;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the services in code: config files load differently across Symfony 5.4 to 8.
 */
final class DtoGeneratorExtension extends Extension
{
    // `list` shows it without building the command; the command itself copies the core's.
    private const DESCRIPTION = 'Generates DTO classes from the OpenAPI schemas named in the config.';

    private const BUILD_ROOT = '%kernel.build_dir%/dto-generator';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        // The runtime bundle registers the same definition: an application may enable either or both.
        Normalizers::register($container);

        $path = $config['config'];
        if (!is_string($path)) {
            throw new InvalidConfigurationException(sprintf('dto_generator.config must be a path, not %s.', gettype($path)));
        }

        $build = $config['mode'] === Configuration::BUILD;
        if ($build) {
            $container->setParameter(BuildDtosPass::CONFIG, $path);
            $container->setParameter(BuildDtosPass::ROOT, self::BUILD_ROOT);
        }

        $container->register(GenerateCommand::class, GenerateCommand::class)
            ->setArguments([$config['config'], '%kernel.project_dir%', $build ? self::BUILD_ROOT : null])
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

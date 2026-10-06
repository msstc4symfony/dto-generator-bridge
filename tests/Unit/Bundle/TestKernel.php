<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Bundle;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\DtoGeneratorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A Symfony application with FrameworkBundle and the bridge's bundle, in a temporary project.
 */
final class TestKernel extends Kernel
{
    private string $project;

    /** @var array<string, string|bool> */
    private array $bundleConfig;

    /**
     * @param array<string, string|bool> $bundleConfig the dto_generator section
     */
    public function __construct(string $project, array $bundleConfig)
    {
        $this->project = $project;
        $this->bundleConfig = $bundleConfig;
        parent::__construct('test', true);
    }

    /**
     * @return iterable<FrameworkBundle|DtoGeneratorBundle>
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DtoGeneratorBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['secret' => 'test', 'test' => true]);
            $container->loadFromExtension('dto_generator', $this->bundleConfig);
            $container->register('logger', RecordingLogger::class)->setPublic(true);
        });
    }

    public function getProjectDir(): string
    {
        return $this->project;
    }

    public function getCacheDir(): string
    {
        // One container per bundle config: a kernel reuses the container compiled in its cache directory.
        return $this->project . '/var/cache/' . md5((string) json_encode($this->bundleConfig));
    }

    public function getLogDir(): string
    {
        return $this->project . '/var/log';
    }
}

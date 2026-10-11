<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection\CompilerPass;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Status;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\Build\ClassMapLoader;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\Build\GenerationFailed;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The build mode (`mode: build`): generates the DTOs into the build directory while the container is built, ahead of
 * the passes that read controller signatures, and keeps the specifications as the container's resources, so an edit
 * rebuilds both. Remote documents come from the committed cache only.
 */
final class BuildDtosPass implements CompilerPassInterface
{
    public const CONFIG = 'dto_generator.build.config';

    public const ROOT = 'dto_generator.build.root';

    public const CLASSES = 'dto_generator.build.classes';

    /** @var (callable(Input): Output)|null */
    private $generator;

    /**
     * @param (callable(Input): Output)|null $generator internal, for tests; the core's generator by default
     */
    public function __construct(?callable $generator = null)
    {
        $this->generator = $generator;
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::CONFIG)) {
            return;
        }

        $config = Path::resolve($this->path($container, 'kernel.project_dir'), $this->path($container, self::CONFIG));
        $root = $this->path($container, self::ROOT);
        $generator = $this->generator ?? DtoGenerator::generator();
        $output = $generator((new Input($config, Mode::from(Mode::WRITE)))->withOutputRoot($root)->withoutFetching());

        foreach ($output->documents() as $document) {
            $container->addResource(new FileResource($document));
        }

        $cache = $output->remoteCache();
        if ($cache !== null) {
            $container->addResource(new DirectoryResource($cache));
        }

        $status = $output->status()->value();
        if ($status !== Status::OK) {
            $errors = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->errors());

            throw new GenerationFailed(sprintf('dto-generator could not build the DTOs of %s: %s', $config, $errors === [] ? $status : implode(' ', $errors)));
        }

        foreach ($output->diagnostics()->all() as $diagnostic) {
            $container->log($this, 'dto-generator: ' . $diagnostic->toString());
        }

        $container->setParameter(self::CLASSES, $output->classes());
        // The passes after this one reflect controllers and services, which may name the DTOs.
        ClassMapLoader::register($output->classes());
    }

    private function path(ContainerBuilder $container, string $name): string
    {
        $value = $container->getParameterBag()->resolveValue($container->getParameter($name));
        if (!is_string($value)) {
            throw new GenerationFailed(sprintf('The parameter %s must be a path, not %s.', $name, gettype($value)));
        }

        return $value;
    }
}

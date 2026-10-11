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
use ReflectionClass;
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

        // A failed build caches no container, so it needs no resources; a missing config would fail them too.
        $status = $output->status()->value();
        if ($status !== Status::OK) {
            $errors = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->errors());

            throw new GenerationFailed(sprintf('dto-generator could not build the DTOs of %s: %s', $config, $errors === [] ? $status : implode(' ', $errors)));
        }

        foreach ($output->documents() as $document) {
            $container->addResource(new FileResource($document));
        }

        $cache = $output->remoteCache();
        if ($cache !== null) {
            $container->addResource(new DirectoryResource($cache));
        }

        foreach ($output->diagnostics()->all() as $diagnostic) {
            $container->log($this, 'dto-generator: ' . $diagnostic->toString());
        }

        $classes = $output->classes();
        $container->setParameter(self::CLASSES, $classes);
        // The passes after this one reflect controllers and services, which may name the DTOs.
        ClassMapLoader::add($classes);
        foreach ($classes as $class => $file) {
            $loaded = class_exists($class, false) ? (new ReflectionClass($class))->getFileName() : false;
            // By content: cache:clear builds into another directory, and a symlinked var/ resolves differently.
            if ($loaded !== false && hash_file('sha256', $loaded) !== hash_file('sha256', $file)) {
                $container->log($this, sprintf('dto-generator: %s was loaded from %s before this build and changed since; the process and the metadata its warmers cache keep the old version, so clear the cache again.', $class, $loaded));
            }
        }
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

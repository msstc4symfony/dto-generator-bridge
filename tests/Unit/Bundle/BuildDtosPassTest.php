<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Bundle;

use FilesystemIterator;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\GeneratedFile;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Status;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\Build\GenerationFailed;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection\CompilerPass\BuildDtosPass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The build mode's generation on a bare container (spec 2026-10-11 §3).
 */
final class BuildDtosPassTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        if (!class_exists(ContainerBuilder::class)) {
            self::markTestSkipped('symfony/dependency-injection is not installed; the CI matrix installs it.');
        }

        $this->project = sys_get_temp_dir() . '/dto-bridge-build-' . bin2hex(random_bytes(4));
        mkdir($this->project);
        file_put_contents($this->project . '/composer.json', '{}');
        file_put_contents($this->project . '/api.yaml', "openapi: 3.1.0\ninfo: {title: Pets, version: '1'}\npaths: {}\ncomponents:\n  schemas:\n    Pet:\n      type: object\n      properties:\n        name: {type: string}\n");
    }

    protected function tearDown(): void
    {
        if (!isset($this->project) || !is_dir($this->project)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->project, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->project);
    }

    public function testDoesNothingOutsideTheBuildMode(): void
    {
        $container = new ContainerBuilder();

        (new BuildDtosPass())->process($container);

        self::assertFalse($container->hasParameter(BuildDtosPass::CLASSES));
    }

    public function testBuildsTheDtosUnderTheBuildDirectoryAndLoadsThem(): void
    {
        $namespace = 'App\\Built' . bin2hex(random_bytes(4));
        $container = $this->container($namespace, '%kernel.project_dir%/dto-generator.yaml');

        (new BuildDtosPass())->process($container);

        $file = $this->project . '/var/build/dto-generator/src/Dto/Pet.php';
        self::assertFalse(class_exists($namespace . '\\Pet', false));
        self::assertSame([], $container->getCompiler()->getLog());
        self::assertSame([$namespace . '\\Pet' => $file], $container->getParameter(BuildDtosPass::CLASSES));
        self::assertFileExists($file);
        self::assertDirectoryDoesNotExist($this->project . '/src');
        self::assertTrue(class_exists($namespace . '\\Pet'));
        self::assertEquals([
            new FileResource($this->project . '/dto-generator.yaml'),
            new FileResource($this->project . '/api.yaml'),
        ], $container->getResources());
    }

    public function testResolvesARelativeConfigAgainstTheProjectDirectory(): void
    {
        $container = $this->container('App\\Built' . bin2hex(random_bytes(4)), 'dto-generator.yaml');

        (new BuildDtosPass())->process($container);

        self::assertFileExists($this->project . '/var/build/dto-generator/src/Dto/Pet.php');
    }

    public function testRunsWithoutTheNetworkUnderTheBuildRoot(): void
    {
        $inputs = [];
        $container = $this->container('App\\Unused', 'dto-generator.yaml');

        (new BuildDtosPass(static function (Input $input) use (&$inputs): Output {
            $inputs[] = $input;

            return new Output(Status::from(Status::OK), new Diagnostics(), null, []);
        }))->process($container);

        self::assertCount(1, $inputs);
        self::assertSame($this->project . '/dto-generator.yaml', $inputs[0]->configPath());
        self::assertSame('write', $inputs[0]->mode()->value());
        self::assertSame($this->project . '/var/build/dto-generator', $inputs[0]->outputRoot());
        self::assertFalse($inputs[0]->fetchesRemote());
    }

    public function testWatchesTheRemoteCache(): void
    {
        mkdir($this->project . '/remote');
        $container = $this->container('App\\Unused', 'dto-generator.yaml');

        (new BuildDtosPass(fn (): Output => new Output(Status::from(Status::OK), new Diagnostics(), null, [], [$this->project . '/dto-generator.yaml'], $this->project . '/remote')))->process($container);

        self::assertEquals([
            new FileResource($this->project . '/dto-generator.yaml'),
            new DirectoryResource($this->project . '/remote'),
        ], $container->getResources());
    }

    public function testLogsTheWarnings(): void
    {
        $diagnostics = new Diagnostics();
        $diagnostics->warning('Unknown string format "color".', new SchemaLocation('/api.yaml'));

        $container = $this->container('App\\Unused', 'dto-generator.yaml');
        $pass = new BuildDtosPass(static fn (): Output => new Output(Status::from(Status::OK), $diagnostics, null, []));

        $pass->process($container);

        self::assertSame([BuildDtosPass::class . ': dto-generator: warning /api.yaml#: Unknown string format "color".'], $container->getCompiler()->getLog());
    }

    public function testFailsTheBuildWithTheErrors(): void
    {
        file_put_contents($this->project . '/api.yaml', 'openapi: [');
        $container = $this->container('App\\Unused', 'dto-generator.yaml');

        try {
            (new BuildDtosPass())->process($container);
            self::fail('The build went on.');
        } catch (GenerationFailed $exception) {
            self::assertStringStartsWith('dto-generator could not build the DTOs of ' . $this->project . '/dto-generator.yaml: error ' . $this->project . '/dto-generator.yaml#/sources/0/spec: File "' . $this->project . '/api.yaml" is not valid', $exception->getMessage());
        }

        self::assertFalse($container->hasParameter(BuildDtosPass::CLASSES));
        self::assertSame([], $container->getResources());
    }

    public function testWarnsAboutADtoLoadedBeforeTheBuild(): void
    {
        $class = 'App\\Early' . bin2hex(random_bytes(4)) . '\\Pet';
        $old = $this->project . '/OldPet.php';
        file_put_contents($old, sprintf("<?php\nnamespace %s;\nfinal class Pet {}\n", substr($class, 0, -4)));
        require $old;
        $container = $this->container('App\\Unused', 'dto-generator.yaml');
        $built = static fn (string $directory): Output => new Output(Status::from(Status::OK), new Diagnostics(), null, [new GeneratedFile($directory, 'OldPet.php', '', $class)]);

        (new BuildDtosPass(fn (): Output => $built($this->project)))->process($container);
        self::assertSame([], $container->getCompiler()->getLog());

        (new BuildDtosPass(static fn (): Output => $built('/build')))->process($container);
        self::assertSame([BuildDtosPass::class . ': dto-generator: ' . $class . ' was loaded from ' . $old . ' before this build; the process keeps that version until it restarts.'], $container->getCompiler()->getLog());
    }

    public function testNeedsPathsForParameters(): void
    {
        $container = $this->container('App\\Unused', 'dto-generator.yaml');
        $container->setParameter(BuildDtosPass::ROOT, ['build']);

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('The parameter dto_generator.build.root must be a path, not array.');

        (new BuildDtosPass())->process($container);
    }

    public function testNamesTheStatusOfAFailureWithoutErrors(): void
    {
        $container = $this->container('App\\Unused', 'dto-generator.yaml');

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('dto-generator could not build the DTOs of ' . $this->project . '/dto-generator.yaml: generation-failed');

        (new BuildDtosPass(static fn (): Output => new Output(Status::from(Status::GENERATION_FAILED), new Diagnostics(), null, [])))->process($container);
    }

    private function container(string $namespace, string $config): ContainerBuilder
    {
        file_put_contents($this->project . '/dto-generator.yaml', sprintf("version: 1\ntarget: {php: '8.2'}\nverifyClasses: false\ndiscoverExtensions: false\nsources:\n  - {spec: api.yaml, namespace: '%s', outputDir: src/Dto}\n", $namespace));
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->project);
        $container->setParameter('kernel.build_dir', $this->project . '/var/build');
        $container->setParameter(BuildDtosPass::CONFIG, $config);
        $container->setParameter(BuildDtosPass::ROOT, '%kernel.build_dir%/dto-generator');

        return $container;
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Bundle;

use FilesystemIterator;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\CacheWarmer\GenerationCheckWarmer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The bundle in a real Symfony application (bridge spec §7).
 */
final class DtoGeneratorBundleTest extends TestCase
{
    private string $project;

    private bool $handlesErrors = false;

    protected function setUp(): void
    {
        // Symfony's own deprecations on a newer PHP (5.4's ErrorHandler and E_STRICT on 8.4) are not the bundle's:
        // only those raised outside vendor/ reach PHPUnit.
        $previous = null;
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous): bool {
            if (strpos($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
                return true;
            }

            return is_callable($previous) && (bool) $previous($level, $message, $file, $line);
        }, E_USER_DEPRECATED | E_DEPRECATED);
        $this->handlesErrors = true;

        if (!class_exists(FrameworkBundle::class)) {
            self::markTestSkipped('symfony/framework-bundle is not installed; the CI matrix installs it.');
        }

        $this->project = sys_get_temp_dir() . '/dto-bridge-bundle-' . bin2hex(random_bytes(4));
        mkdir($this->project);
        file_put_contents($this->project . '/composer.json', '{}');
        file_put_contents($this->project . '/api.yaml', "openapi: 3.1.0\ninfo: {title: Pets, version: '1'}\npaths: {}\ncomponents:\n  schemas:\n    Pet:\n      type: object\n      properties:\n        name: {type: string}\n");
        file_put_contents($this->project . '/dto-generator.yaml', "version: 1\ntarget: {php: '8.2'}\nverifyClasses: false\ndiscoverExtensions: false\nsources:\n  - {spec: api.yaml, namespace: App\\Dto, outputDir: src/Dto}\n");
    }

    protected function tearDown(): void
    {
        if ($this->handlesErrors) {
            restore_error_handler();
        }

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

    public function testGeneratesWithTheConfiguredConfigAndTheCoreExitCodes(): void
    {
        $command = $this->command([]);

        self::assertSame(1, $command->execute(['--check' => true]));
        self::assertStringContainsString('create src/Dto/Pet.php', $command->getDisplay());
        self::assertSame(0, $command->execute(['--dry-run' => true]));
        self::assertFileDoesNotExist($this->project . '/src/Dto/Pet.php');
        self::assertSame(0, $command->execute([]));
        self::assertFileExists($this->project . '/src/Dto/Pet.php');
        self::assertSame(0, $command->execute(['--check' => true]));
    }

    public function testTakesAnotherConfigFromTheOptionOrTheBundle(): void
    {
        rename($this->project . '/dto-generator.yaml', $this->project . '/dtos.yaml');

        self::assertSame(3, $this->command([])->execute([]));
        $explicit = $this->command([]);
        self::assertSame(0, $explicit->execute(['--config' => $this->project . '/dtos.yaml']), $explicit->getDisplay());
        $configured = $this->command(['config' => '%kernel.project_dir%/dtos.yaml']);
        self::assertSame(0, $configured->execute(['--check' => true]), $configured->getDisplay());
    }

    public function testWarnsOnWarmupWhenTheDtosAreOutOfDate(): void
    {
        $kernel = new TestKernel($this->project, ['check_on_warmup' => true]);
        $kernel->boot();

        $warmer = $this->service($kernel, GenerationCheckWarmer::class);
        assert($warmer instanceof GenerationCheckWarmer);
        $logger = $this->service($kernel, 'logger');
        assert($logger instanceof RecordingLogger);

        self::assertTrue($warmer->isOptional());
        self::assertSame([], $warmer->warmUp($kernel->getCacheDir()));
        self::assertSame(['warning: The DTOs generated from ' . $this->project . '/dto-generator.yaml are out of date; run bin/console dto-generator:generate.'], $logger->records);
        self::assertFileDoesNotExist($this->project . '/src/Dto/Pet.php');

        $this->command(['check_on_warmup' => true])->execute([]);
        $logger->records = [];
        $warmer->warmUp($kernel->getCacheDir());
        self::assertSame([], $logger->records);
        $kernel->shutdown();
    }

    public function testWarnsOnWarmupWhenTheCheckFails(): void
    {
        file_put_contents($this->project . '/api.yaml', 'openapi: [');
        $kernel = new TestKernel($this->project, ['check_on_warmup' => true]);
        $kernel->boot();

        $warmer = $this->service($kernel, GenerationCheckWarmer::class);
        assert($warmer instanceof GenerationCheckWarmer);
        $logger = $this->service($kernel, 'logger');
        assert($logger instanceof RecordingLogger);

        $warmer->warmUp($kernel->getCacheDir());

        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('warning: dto-generator could not check ' . $this->project . '/dto-generator.yaml: ', $logger->records[0]);
        $kernel->shutdown();
    }

    public function testLoadsTheCommandLazily(): void
    {
        $kernel = new TestKernel($this->project, []);
        $kernel->boot();

        $loader = $kernel->getContainer()->get('console.command_loader');

        self::assertInstanceOf(CommandLoaderInterface::class, $loader);
        self::assertTrue($loader->has('dto-generator:generate'));
        $kernel->shutdown();
    }

    public function testChecksNothingOnWarmupByDefault(): void
    {
        $kernel = new TestKernel($this->project, []);
        $kernel->boot();

        self::assertFalse($kernel->getContainer()->has('test.service_container') && $this->testContainer($kernel)->has(GenerationCheckWarmer::class));
        $kernel->shutdown();
    }

    /**
     * @param array<string, string|bool> $bundleConfig
     */
    private function command(array $bundleConfig): CommandTester
    {
        $kernel = new TestKernel($this->project, $bundleConfig);

        return new CommandTester((new Application($kernel))->find('dto-generator:generate'));
    }

    private function service(TestKernel $kernel, string $id): object
    {
        $service = $this->testContainer($kernel)->get($id);
        self::assertIsObject($service);

        return $service;
    }

    private function testContainer(TestKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}

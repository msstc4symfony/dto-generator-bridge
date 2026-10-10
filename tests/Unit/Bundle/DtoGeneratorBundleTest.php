<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Bundle;

use FilesystemIterator;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Status;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\CacheWarmer\GenerationCheckWarmer;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalPropertiesNormalizer;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Box;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * The bundle in a real Symfony application (bridge spec §7).
 */
final class DtoGeneratorBundleTest extends TestCase
{
    private string $project;

    private bool $handlesErrors = false;

    /** @var array{string|false, string|false, string|false} memory_limit, display_errors, DTO_GENERATOR_MEMORY_LIMIT */
    private array $process;

    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        // Symfony's own deprecations on a newer PHP (5.4's ErrorHandler and E_STRICT on 8.4) are not the bundle's:
        // only those raised outside vendor/, or in the generator the bundle runs, reach PHPUnit.
        $vendor = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
        $previous = null;
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous, $vendor): bool {
            if (strpos($file, $vendor) !== false && strpos($file, $vendor . 'msstc4php' . DIRECTORY_SEPARATOR) === false) {
                return true;
            }

            return is_callable($previous) && (bool) $previous($level, $message, $file, $line);
        }, E_USER_DEPRECATED | E_DEPRECATED);
        $this->handlesErrors = true;
        // The command changes the process as the generator's CLI does; the suite runs on in this one.
        $this->process = [ini_get('memory_limit'), ini_get('display_errors'), getenv('DTO_GENERATOR_MEMORY_LIMIT')];
        putenv('DTO_GENERATOR_MEMORY_LIMIT');

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
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }

        if ($this->handlesErrors) {
            restore_error_handler();
            [$memoryLimit, $displayErrors, $configured] = $this->process;
            ini_set('memory_limit', (string) $memoryLimit);
            ini_set('display_errors', (string) $displayErrors);
            putenv($configured === false ? 'DTO_GENERATOR_MEMORY_LIMIT' : 'DTO_GENERATOR_MEMORY_LIMIT=' . $configured);
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

    public function testPreparesTheProcessAsTheGeneratorCliDoes(): void
    {
        self::assertLessThan(256 * 1024 * 1024, memory_get_usage(), 'The suite must run below 256M for this test.');
        self::assertNotFalse(ini_set('memory_limit', '256M'));
        ini_set('display_errors', 'stdout');

        self::assertSame(0, $this->command([])->execute(['--dry-run' => true]));

        self::assertSame('1G', ini_get('memory_limit'));
        self::assertSame('stderr', ini_get('display_errors'));
    }

    public function testLeavesTheProcessAloneOnWarmup(): void
    {
        $kernel = $this->bootedKernel(['check_on_warmup' => true]);
        $warmer = $this->service($kernel, GenerationCheckWarmer::class);
        assert($warmer instanceof GenerationCheckWarmer);
        self::assertLessThan(256 * 1024 * 1024, memory_get_usage(), 'The suite must run below 256M for this test.');
        self::assertNotFalse(ini_set('memory_limit', '256M'));
        ini_set('display_errors', 'stdout');

        $warmer->warmUp($kernel->getCacheDir());

        self::assertSame('256M', ini_get('memory_limit'));
        self::assertSame('stdout', ini_get('display_errors'));
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

    public function testResolvesARelativeConfigAgainstTheProjectDirectory(): void
    {
        rename($this->project . '/dto-generator.yaml', $this->project . '/dtos.yaml');

        $configured = $this->command(['config' => 'dtos.yaml']);
        self::assertSame(0, $configured->execute([]), $configured->getDisplay());
        $explicit = $this->command(['config' => 'none.yaml']);
        self::assertSame(0, $explicit->execute(['--config' => 'dtos.yaml', '--check' => true]), $explicit->getDisplay());
    }

    public function testKeepsTheBundleConfigForAnEmptyOption(): void
    {
        rename($this->project . '/dto-generator.yaml', $this->project . '/dtos.yaml');
        $command = $this->command(['config' => 'dtos.yaml']);

        self::assertSame(0, $command->execute(['--config' => '', '--dry-run' => true]), $command->getDisplay());
    }

    public function testAnswersAMistypedOptionWithTheUsageExitCode(): void
    {
        $text = $this->command([]);
        self::assertSame(3, $text->execute(['--chek' => true]));
        self::assertStringContainsString('error: The "--chek" option does not exist.', $text->getDisplay());

        $json = $this->command([]);
        self::assertSame(3, $json->execute(['--chek' => true, '--format' => 'json']));
        $report = json_decode($json->getDisplay(), true);
        self::assertIsArray($report);
        self::assertSame('config-failed', $report['status']);
    }

    public function testPassesAnEmptyFormatToTheCore(): void
    {
        $command = $this->command([]);

        self::assertSame(3, $command->execute(['--format' => '']), $command->getDisplay());
    }

    public function testShowsTheBundleConfigInTheHelp(): void
    {
        $kernel = new TestKernel($this->project, ['config' => 'dtos.yaml']);
        $this->kernels[] = $kernel;
        $definition = (new Application($kernel))->find('dto-generator:generate')->getDefinition();

        self::assertSame('Config file, relative to the project directory (default: ' . $this->project . '/dtos.yaml)', $definition->getOption('config')->getDescription());
        self::assertSame('Write nothing; exit 1 when the output is out of date', $definition->getOption('check')->getDescription());
    }

    public function testWarnsOnWarmupWhenTheDtosAreOutOfDate(): void
    {
        $kernel = $this->bootedKernel(['check_on_warmup' => true]);

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
    }

    public function testWarnsOnWarmupWhenTheCheckFails(): void
    {
        file_put_contents($this->project . '/api.yaml', 'openapi: [');
        $kernel = $this->bootedKernel(['check_on_warmup' => true]);

        $warmer = $this->service($kernel, GenerationCheckWarmer::class);
        assert($warmer instanceof GenerationCheckWarmer);
        $logger = $this->service($kernel, 'logger');
        assert($logger instanceof RecordingLogger);

        $warmer->warmUp($kernel->getCacheDir());

        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('warning: dto-generator could not check ' . $this->project . '/dto-generator.yaml: ', $logger->records[0]);
    }

    public function testWarnsOnWarmupForARelativeConfig(): void
    {
        $kernel = $this->bootedKernel(['config' => 'dto-generator.yaml', 'check_on_warmup' => true]);
        $logger = $this->service($kernel, 'logger');
        assert($logger instanceof RecordingLogger);
        $warmer = $this->service($kernel, GenerationCheckWarmer::class);
        assert($warmer instanceof GenerationCheckWarmer);

        $warmer->warmUp($kernel->getCacheDir());

        self::assertSame(['warning: The DTOs generated from ' . $this->project . '/dto-generator.yaml are out of date; run bin/console dto-generator:generate.'], $logger->records);
    }

    public function testWarnsOnWarmupWhenTheCheckCrashes(): void
    {
        $logger = new RecordingLogger();
        $warmer = new GenerationCheckWarmer($this->project . '/dto-generator.yaml', $this->project, $logger, static function (): Output {
            throw new RuntimeException('boom');
        });

        self::assertSame([], $warmer->warmUp($this->project . '/var/cache'));
        self::assertSame(['warning: dto-generator could not check ' . $this->project . '/dto-generator.yaml: boom'], $logger->records);
    }

    public function testNamesTheStatusWhenAFailedCheckGivesNoErrors(): void
    {
        $logger = new RecordingLogger();
        $warmer = new GenerationCheckWarmer('dto-generator.yaml', $this->project, $logger, static fn (): Output => new Output(Status::from(Status::GENERATION_FAILED), new Diagnostics(), null, []));

        $warmer->warmUp($this->project . '/var/cache');

        self::assertSame(['warning: dto-generator could not check ' . $this->project . '/dto-generator.yaml: generation-failed'], $logger->records);
    }

    public function testChecksOnTheRealCacheWarmup(): void
    {
        $kernel = $this->bootedKernel(['check_on_warmup' => true]);
        $logger = $this->service($kernel, 'logger');
        assert($logger instanceof RecordingLogger);
        $logger->records = [];

        $warmup = new CommandTester((new Application($kernel))->find('cache:warmup'));

        self::assertSame(0, $warmup->execute([]), $warmup->getDisplay());
        self::assertContains('warning: The DTOs generated from ' . $this->project . '/dto-generator.yaml are out of date; run bin/console dto-generator:generate.', $logger->records);
    }

    public function testLoadsTheCommandLazily(): void
    {
        $kernel = $this->bootedKernel([]);

        $loader = $kernel->getContainer()->get('console.command_loader');

        self::assertInstanceOf(CommandLoaderInterface::class, $loader);
        self::assertTrue($loader->has('dto-generator:generate'));
        self::assertSame(DtoGenerator::console()->find('generate')->getDescription(), $this->listedDescription($kernel));
    }

    public function testChecksNothingOnWarmupByDefault(): void
    {
        $kernel = $this->bootedKernel([]);

        self::assertTrue($kernel->getContainer()->has('test.service_container'));
        self::assertFalse($this->testContainer($kernel)->has(GenerationCheckWarmer::class));
    }

    /**
     * @requires PHP 8.0
     */
    public function testSpreadsAdditionalPropertiesInTheApplicationsSerializer(): void
    {
        $serializer = $this->service($this->bootedKernel([], ['serializer' => ['enabled' => true], 'property_info' => ['enabled' => true]]), 'serializer');

        self::assertInstanceOf(NormalizerInterface::class, $serializer);
        self::assertInstanceOf(DenormalizerInterface::class, $serializer);
        self::assertSame(['pears' => 1], $serializer->normalize(new Box(['pears' => 1])));
        $box = $serializer->denormalize(['apples' => 2], Box::class);
        self::assertInstanceOf(Box::class, $box);
        self::assertSame(['apples' => 2], $box->getExtra());
    }

    public function testRegistersNoNormalizerWithoutTheSerializer(): void
    {
        $kernel = $this->bootedKernel([], ['serializer' => ['enabled' => false]]);

        self::assertFalse($this->testContainer($kernel)->has('serializer'));
        self::assertFalse($this->testContainer($kernel)->has(AdditionalPropertiesNormalizer::class));
    }

    /**
     * @param array<string, string|bool> $bundleConfig
     */
    private function command(array $bundleConfig): CommandTester
    {
        $kernel = new TestKernel($this->project, $bundleConfig);
        $this->kernels[] = $kernel;

        return new CommandTester((new Application($kernel))->find('dto-generator:generate'));
    }

    /**
     * @param array<string, string|bool> $bundleConfig
     * @param array<string, array<string, bool>> $frameworkConfig
     */
    private function bootedKernel(array $bundleConfig, array $frameworkConfig = []): TestKernel
    {
        $kernel = new TestKernel($this->project, $bundleConfig, $frameworkConfig);
        $this->kernels[] = $kernel;
        $kernel->boot();

        return $kernel;
    }

    /**
     * The description `list` shows without instantiating the command.
     */
    private function listedDescription(TestKernel $kernel): string
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $list = new BufferedOutput();
        $application->run(new ArrayInput(['command' => 'list', '--raw' => true]), $list);
        $lines = preg_grep('#^dto-generator:generate\s#', explode("\n", $list->fetch()));
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = reset($lines);
        self::assertIsString($line);

        return trim((string) preg_replace('#^dto-generator:generate\s+#', '', $line));
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

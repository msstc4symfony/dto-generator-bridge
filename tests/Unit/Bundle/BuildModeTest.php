<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Bundle;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection\CompilerPass\BuildDtosPass;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\DependencyInjection\Configuration;
use Msstc4Symfony\DtoGeneratorBridge\Bundle\DtoGeneratorBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The build mode's settings and the bundle's loader, without booting a kernel (which outlasts Infection's timeout).
 */
final class BuildModeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Bundle::class)) {
            self::markTestSkipped('symfony/http-kernel is not installed; the CI matrix installs it.');
        }
    }

    public function testKeepsTheDtosInTheSourcesByDefault(): void
    {
        self::assertSame('committed', $this->settings([])['mode']);
        self::assertSame('committed', $this->settings(['mode' => 'committed'])['mode']);
        self::assertSame('build', $this->settings(['mode' => 'build'])['mode']);
    }

    public function testLoadsTheBuiltClassesOnBoot(): void
    {
        $class = 'App\\Booted' . bin2hex(random_bytes(4)) . '\\Pet';
        $file = sys_get_temp_dir() . '/dto-bridge-boot-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, sprintf("<?php\nnamespace %s;\nfinal class Pet {}\n", substr($class, 0, -4)));
        $container = new Container();
        $container->setParameter(BuildDtosPass::CLASSES, [$class => $file]);

        $bundle = new DtoGeneratorBundle();
        $bundle->setContainer($container);

        try {
            $bundle->boot();

            self::assertTrue(class_exists($class));
        } finally {
            unlink($file);
        }
    }

    public function testBootsWithoutBuiltClasses(): void
    {
        $before = count(spl_autoload_functions());
        (new DtoGeneratorBundle())->boot();
        $bundle = new DtoGeneratorBundle();
        $bundle->setContainer(new Container());
        $bundle->boot();

        self::assertCount($before, spl_autoload_functions());
    }

    /**
     * @param array<string, string> $section
     *
     * @return array<array-key, mixed>
     */
    private function settings(array $section): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$section]);
    }
}

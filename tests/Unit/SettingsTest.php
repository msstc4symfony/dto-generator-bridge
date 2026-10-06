<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit;

use InvalidArgumentException;
use Msstc4Symfony\DtoGeneratorBridge\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testLeavesEverythingToTheProjectByDefault(): void
    {
        $settings = Settings::fromConfig([]);

        self::assertNull($settings->validator());
        self::assertNull($settings->serializer());
        self::assertNull($settings->version());
        self::assertSame([], $settings->groups());
    }

    public function testReadsEveryKey(): void
    {
        $settings = Settings::fromConfig(['validator' => true, 'serializer' => false, 'version' => '6.4', 'groups' => ['api', 'admin']]);

        self::assertTrue($settings->validator());
        self::assertFalse($settings->serializer());
        self::assertNotNull($settings->version());
        self::assertSame('6.4', $settings->version()->toString());
        self::assertSame(['api', 'admin'], $settings->groups());
    }

    public function testTakesAutoForTheProjectsChoice(): void
    {
        $settings = Settings::fromConfig(['validator' => 'auto', 'serializer' => 'auto', 'version' => 'auto']);

        self::assertNull($settings->validator());
        self::assertNull($settings->serializer());
        self::assertNull($settings->version());
    }

    /**
     * @return iterable<string, array{array<string, array<array-key, int|string>|bool|float|int|string|null>, string}>
     */
    public function invalidConfigs(): iterable
    {
        yield 'unknown key' => [['colour' => 'red'], 'extensionConfig.symfony.colour is not a setting of the Symfony bridge.'];
        yield 'validator' => [['validator' => 'yes'], 'extensionConfig.symfony.validator must be auto, true or false.'];
        yield 'serializer' => [['serializer' => 1], 'extensionConfig.symfony.serializer must be auto, true or false.'];
        yield 'version format' => [['version' => '7'], 'extensionConfig.symfony.version must be auto or a version like "6.4" (quote it in YAML: \'6.4\').'];
        yield 'version type' => [['version' => 6.4], 'extensionConfig.symfony.version must be auto or a version like "6.4" (quote it in YAML: \'6.4\').'];
        yield 'version too old' => [['version' => '4.4'], 'extensionConfig.symfony.version must be 5.4 or newer.'];
        yield 'validator null' => [['validator' => null], 'extensionConfig.symfony.validator must be auto, true or false.'];
        yield 'version null' => [['version' => null], 'extensionConfig.symfony.version must be auto or a version like "6.4" (quote it in YAML: \'6.4\').'];
        yield 'groups null' => [['groups' => null], 'extensionConfig.symfony.groups must be a list of group names.'];
        yield 'groups repeated' => [['groups' => ['api', 'admin', 'api']], 'extensionConfig.symfony.groups names "api" more than once.'];
        yield 'several' => [['colour' => 'red', 'validator' => 1, 'groups' => 'api'], 'extensionConfig.symfony.colour is not a setting of the Symfony bridge. extensionConfig.symfony.validator must be auto, true or false. extensionConfig.symfony.groups must be a list of group names.'];
        yield 'groups type' => [['groups' => 'api'], 'extensionConfig.symfony.groups must be a list of group names.'];
        yield 'groups item' => [['groups' => ['api', '']], 'extensionConfig.symfony.groups must be a list of group names.'];
        yield 'groups map' => [['groups' => ['a' => 'api']], 'extensionConfig.symfony.groups must be a list of group names.'];
        yield 'groups items' => [['groups' => [1, 2]], 'extensionConfig.symfony.groups must be a list of group names.'];
        yield 'groups repeated thrice' => [['groups' => ['api', 'api', 'api']], 'extensionConfig.symfony.groups names "api" more than once.'];
    }

    /**
     * @dataProvider invalidConfigs
     *
     * @param array<string, array<array-key, int|string>|bool|float|int|string|null> $config
     */
    public function testRefusesAConfigItCannotUse(array $config, string $message): void
    {
        try {
            Settings::fromConfig($config);
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail('No exception');
    }
}

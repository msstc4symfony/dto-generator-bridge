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
        self::assertFalse($settings->spreadsAdditionalProperties());
    }

    public function testReadsEveryKey(): void
    {
        $settings = Settings::fromConfig(['validator' => true, 'serializer' => false, 'version' => '6.4', 'groups' => ['api', 'admin'], 'additionalProperties' => 'spread']);

        self::assertTrue($settings->validator());
        self::assertFalse($settings->serializer());
        self::assertNotNull($settings->version());
        self::assertSame('6.4', $settings->version()->toString());
        self::assertSame(['api', 'admin'], $settings->groups());
        self::assertTrue($settings->spreadsAdditionalProperties());
    }

    public function testIgnoresTheUndeclaredPropertiesWhenAskedTo(): void
    {
        self::assertFalse(Settings::fromConfig(['additionalProperties' => 'ignore'])->spreadsAdditionalProperties());
    }

    public function testTakesAutoForTheProjectsChoice(): void
    {
        $settings = Settings::fromConfig(['validator' => 'auto', 'serializer' => 'auto', 'version' => 'auto']);

        self::assertNull($settings->validator());
        self::assertNull($settings->serializer());
        self::assertNull($settings->version());
    }

    public function testChecksEveryFormatByDefault(): void
    {
        self::assertTrue(Settings::fromConfig([])->formatChecks()->allows('phone'));
        self::assertTrue(Settings::fromConfig(['formats' => true])->formatChecks()->allows('phone'));
        self::assertFalse(Settings::fromConfig(['formats' => false])->formatChecks()->allows('phone'));
    }

    public function testTurnsFormatsOnAndOffByTheirNames(): void
    {
        $formatChecks = Settings::fromConfig(['formats' => ['Phone_Number' => false, 'uuid' => true]])->formatChecks();

        self::assertFalse($formatChecks->allows('phone'));
        self::assertTrue($formatChecks->allows('uuid'));
        self::assertTrue($formatChecks->allows('email'));
    }

    public function testChecksAFormatStrictlyWhenTheConfigSaysSo(): void
    {
        $formatChecks = Settings::fromConfig(['formats' => ['phone_number' => 'strict', 'uuid' => true]])->formatChecks();

        self::assertTrue($formatChecks->allows('phone'));
        self::assertTrue($formatChecks->isStrict('phone'));
        self::assertFalse($formatChecks->isStrict('uuid'));
        self::assertFalse(Settings::fromConfig([])->formatChecks()->isStrict('phone'));
    }

    public function testChecksOnlyTheListedFormats(): void
    {
        $formatChecks = Settings::fromConfig(['formats' => ['GUID', 'int8']])->formatChecks();

        self::assertTrue($formatChecks->allows('uuid'));
        self::assertTrue($formatChecks->allows('int8'));
        self::assertFalse($formatChecks->allows('phone'));
    }

    /**
     * @return iterable<string, array{array<string, array<array-key, bool|int|string>|bool|float|int|string|null>, string}>
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
        yield 'additionalProperties' => [['additionalProperties' => 'keep'], 'extensionConfig.symfony.additionalProperties must be ignore or spread.'];
        yield 'additionalProperties type' => [['additionalProperties' => true], 'extensionConfig.symfony.additionalProperties must be ignore or spread.'];
        yield 'groups item' => [['groups' => ['api', '']], 'extensionConfig.symfony.groups must be a list of group names.'];
        yield 'groups map' => [['groups' => ['a' => 'api']], 'extensionConfig.symfony.groups must be a list of group names.'];
        yield 'groups items' => [['groups' => [1, 2]], 'extensionConfig.symfony.groups must be a list of group names.'];
        yield 'formats type' => [['formats' => 'phone'], 'extensionConfig.symfony.formats must be true, false, a list of formats or a map of formats to true, false or strict.'];
        yield 'formats empty' => [['formats' => []], 'extensionConfig.symfony.formats must be true, false, a list of formats or a map of formats to true, false or strict.'];
        yield 'formats switch' => [['formats' => ['phone' => 'no']], 'extensionConfig.symfony.formats must be true, false, a list of formats or a map of formats to true, false or strict.'];
        yield 'formats item' => [['formats' => ['phone', 1]], 'extensionConfig.symfony.formats must be true, false, a list of formats or a map of formats to true, false or strict.'];
        yield 'formats item among unknown ones' => [['formats' => ['odd', 1, 'zzz']], 'extensionConfig.symfony.formats names "odd", which the bridge does not check. extensionConfig.symfony.formats names "zzz", which the bridge does not check. extensionConfig.symfony.formats must be true, false, a list of formats or a map of formats to true, false or strict.'];
        yield 'formats unknown' => [['formats' => ['ssh-key' => false, 'odd' => true]], 'extensionConfig.symfony.formats names "ssh-key", which the bridge does not check. extensionConfig.symfony.formats names "odd", which the bridge does not check.'];
        yield 'formats strict without a library' => [['formats' => ['uuid' => 'strict', 'Phone' => 'strict']], 'extensionConfig.symfony.formats sets "uuid" to strict, which only phone takes.'];
        yield 'formats strict in a list' => [['formats' => ['phone', 'strict']], 'extensionConfig.symfony.formats names "strict", which the bridge does not check.'];
        yield 'formats strict of an unknown one' => [['formats' => ['odd' => 'strict']], 'extensionConfig.symfony.formats sets "odd" to strict, which only phone takes.'];
        yield 'formats date' => [['formats' => ['DateTime' => false]], 'extensionConfig.symfony.formats names "DateTime", which the bridge does not check: a date is a DateTimeInterface.'];
        yield 'formats repeated' => [['formats' => ['phone', 'tel', 'uuid', 'GUID']], 'extensionConfig.symfony.formats names "tel" more than once. extensionConfig.symfony.formats names "GUID" more than once.'];
        yield 'groups repeated thrice' => [['groups' => ['api', 'api', 'api']], 'extensionConfig.symfony.groups names "api" more than once.'];
    }

    /**
     * @dataProvider invalidConfigs
     *
     * @param array<string, array<array-key, bool|int|string>|bool|float|int|string|null> $config
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

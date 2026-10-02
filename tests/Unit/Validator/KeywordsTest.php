<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Validator\Keywords;
use PHPUnit\Framework\TestCase;

/**
 * A schema without `$ref`; the keywords beside a `$ref` are covered with the real generator in ValidatorEnricherTest.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class KeywordsTest extends TestCase
{
    private int $schemas = 0;

    public function testReadsEachValueOnceIncludingNull(): void
    {
        $keywords = new Keywords($this->schema(['const' => null, 'maxLength' => 3]), SchemaReferences::none());

        self::assertSame([null], $keywords->values('const'));
        self::assertTrue($keywords->has('const'));
        self::assertSame([3], $keywords->values('maxLength'));
        self::assertSame([], $keywords->values('pattern'));
        self::assertFalse($keywords->has('pattern'));
    }

    public function testReadsTheEnumAndFormatOfTheSchemaItself(): void
    {
        $keywords = new Keywords($this->schema([], ['a', 'b'], 'email'), SchemaReferences::none());

        self::assertSame(['a', 'b'], $keywords->enum());
        self::assertSame('email', $keywords->format());
        self::assertFalse($keywords->narrowsReferencedEnum());
    }

    public function testCountsACompositionAsAnObjectOnlyWithABranchThatHasProperties(): void
    {
        $object = $this->schema([], null, null, ['name' => $this->schema()]);

        self::assertTrue($this->describesObject($object));
        self::assertFalse($this->describesObject($this->schema([], null, null, [], ['anyOf' => [$this->schema(), $this->schema()]])));
        foreach (['allOf', 'oneOf', 'anyOf'] as $composition) {
            self::assertTrue($this->describesObject($this->schema([], null, null, [], [$composition => [$this->schema(), $object]])), $composition);
        }
    }

    public function testDoesNotEnterACompositionItIsAlreadyIn(): void
    {
        $branch = new Schema(new SchemaLocation('api.yaml', '/loop'), [], null, null, null, false, null, null, ['name' => $this->schema()], [], null, null, [], [], [], null, [], new Extensions());
        $loop = new Schema(new SchemaLocation('api.yaml', '/loop'), [], null, null, null, false, null, null, [], [], null, null, [], [], [$branch], null, [], new Extensions());

        self::assertFalse($this->describesObject($loop));
    }

    private function describesObject(Schema $schema): bool
    {
        return (new Keywords($schema, SchemaReferences::none()))->describesObject();
    }

    /**
     * @param array<string, JsonValue> $keywords
     * @param list<string>|null $enum
     * @param array<string, Schema> $properties
     * @param array{allOf?: list<Schema>, oneOf?: list<Schema>, anyOf?: list<Schema>} $compositions
     */
    private function schema(array $keywords = [], ?array $enum = null, ?string $format = null, array $properties = [], array $compositions = []): Schema
    {
        $location = new SchemaLocation('api.yaml', '/s' . ++$this->schemas);

        return new Schema($location, [], null, $format, null, false, null, $enum, $properties, [], null, null, $compositions['allOf'] ?? [], $compositions['oneOf'] ?? [], $compositions['anyOf'] ?? [], null, $keywords, new Extensions());
    }
}

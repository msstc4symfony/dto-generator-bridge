<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;
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
        self::assertSame(['email'], $keywords->formats());
        self::assertFalse($keywords->narrowsEnum());
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
        $loop = new Schema(new SchemaLocation('api.yaml', '/loop'), [], null, null, null, false, null, null, [], [], null, null, [], [], [$branch, $branch], null, [], new Extensions());

        self::assertFalse($this->describesObject($loop));
    }

    public function testCountsEachEnumValueOnce(): void
    {
        $repeated = $this->schema([], ['a', 'a', 'b']);
        $keywords = new Keywords($this->schema([], ['b', 'a'], null, [], ['allOf' => [$repeated]]), SchemaReferences::none());

        self::assertSame(['b', 'a'], $keywords->enum());
        self::assertFalse($keywords->narrowsEnum());
        self::assertTrue((new Keywords($this->schema([], ['a'], null, [], ['allOf' => [$repeated]]), SchemaReferences::none()))->narrowsEnum());
    }

    public function testComparesValuesAsJsonDoes(): void
    {
        $pairs = [
            [1, 1.0, [1]],
            [1.0, 1, [1.0]],
            ['1', 1, ['1', 1]],
            [1, '1', [1, '1']],
            [1, true, [1, true]],
            ['a', 'a', ['a']],
            [9007199254740993, 9007199254740992, [9007199254740993, 9007199254740992]],
            [2.5, 2.5, [2.5]],
        ];
        foreach ($pairs as [$own, $branch, $expected]) {
            $schema = $this->schema(['const' => $own], null, null, [], ['allOf' => [$this->schema(['const' => $branch])]]);

            self::assertSame($expected, (new Keywords($schema, SchemaReferences::none()))->values('const'), var_export([$own, $branch], true));
        }
    }

    public function testTypesTheValueThroughTheOneTypedAllOfBranch(): void
    {
        $list = $this->schema();
        $typed = new Schema(new SchemaLocation('api.yaml', '/typed'), [SchemaType::from(SchemaType::ARRAY)], null, null, null, false, null, null, [], [], null, null, [], [], [], null, [], new Extensions());
        $untyped = $this->schema(['maxItems' => 2]);

        self::assertSame($typed, $this->resolvedOf($this->schema([], null, null, [], ['allOf' => [$typed, $untyped], 'anyOf' => [$untyped]])));
        $other = new Schema(new SchemaLocation('api.yaml', '/other'), [SchemaType::from(SchemaType::STRING)], null, null, null, false, null, null, [], [], null, null, [], [], [], null, [], new Extensions());
        self::assertSame($typed, $this->resolvedOf($this->schema([], null, null, [], ['anyOf' => [$typed, $this->nullSchema()]])));
        self::assertSame($typed, $this->resolvedOf($this->schema([], null, null, [], ['anyOf' => [$this->nullSchema(), $typed]])));
        self::assertSame($typed, $this->resolvedOf($this->schema([], null, null, [], ['oneOf' => [$typed]])));
        self::assertNotSame($typed, $this->resolvedOf($this->schema([], null, null, [], ['allOf' => [$typed], 'anyOf' => [$typed, $other]])));
        self::assertNotSame($typed, $this->resolvedOf($this->schema([], null, null, [], ['oneOf' => [$typed, $other]])));
        self::assertNotSame($typed, $this->resolvedOf($this->schema([], null, null, ['name' => $list], ['allOf' => [$typed]])));
        self::assertNotSame($typed, $this->resolvedOf($this->schema([], null, null, [], ['allOf' => [$typed, $typed]])));
        $explicit = new Schema(new SchemaLocation('api.yaml', '/explicit'), [], null, null, null, false, null, null, [], [], null, null, [$typed], [], [], null, [], new Extensions(['x-php-type' => 'App\\Tags']));
        self::assertSame($explicit, $this->resolvedOf($explicit));
        self::assertSame([], (new Keywords($list, SchemaReferences::none()))->formats());
    }

    private function nullSchema(): Schema
    {
        return new Schema(new SchemaLocation('api.yaml', '/null'), [SchemaType::from(SchemaType::NULL)], null, null, null, false, null, null, [], [], null, null, [], [], [], null, [], new Extensions());
    }

    private function resolvedOf(Schema $schema): Schema
    {
        return (new Keywords($schema, SchemaReferences::none()))->resolved();
    }

    public function testReadsTheKeywordsOfTheOneUnionMemberBesideNull(): void
    {
        $member = $this->schema(['maxLength' => 3]);
        $nullable = new Schema(new SchemaLocation('api.yaml', '/nullable'), [SchemaType::from(SchemaType::STRING), SchemaType::from(SchemaType::NULL)], null, null, null, false, null, null, [], [], null, null, [], [], [], null, ['minLength' => 1], new Extensions());
        $composed = new Schema(new SchemaLocation('api.yaml', '/composed'), [SchemaType::from(SchemaType::NULL)], null, null, null, false, null, null, [], [], null, null, [$member], [], [], null, ['minLength' => 2], new Extensions());
        $referenced = new Schema(new SchemaLocation('api.yaml', '/referenced'), [SchemaType::from(SchemaType::NULL)], '#/x', null, null, false, null, null, [], [], null, null, [], [], [], null, ['minLength' => 4], new Extensions());

        self::assertSame([3], $this->valuesOf(['anyOf' => [$this->nullSchema(), $member]], 'maxLength'));
        self::assertSame([], $this->valuesOf(['anyOf' => [$member, $this->schema(['maxLength' => 5])]], 'maxLength'));
        self::assertSame([1], $this->valuesOf(['oneOf' => [$nullable, $this->nullSchema()]], 'minLength'));
        self::assertSame([2], $this->valuesOf(['oneOf' => [$composed, $this->nullSchema()]], 'minLength'));
        self::assertSame([4], $this->valuesOf(['oneOf' => [$referenced, $this->nullSchema()]], 'minLength'));
    }

    /**
     * @param array{allOf?: list<Schema>, oneOf?: list<Schema>, anyOf?: list<Schema>} $compositions
     *
     * @return list<mixed>
     */
    private function valuesOf(array $compositions, string $keyword): array
    {
        return (new Keywords($this->schema([], null, null, [], $compositions), SchemaReferences::none()))->values($keyword);
    }

    public function testReadsNoSchemaTwiceOnAnAllOfCycle(): void
    {
        $again = new Schema(new SchemaLocation('api.yaml', '/loop'), [], null, null, null, false, null, null, [], [], null, null, [], [], [], null, ['maxLength' => 9], new Extensions());
        $loop = new Schema(new SchemaLocation('api.yaml', '/loop'), [], null, null, null, false, null, null, [], [], null, null, [$again], [], [], null, [], new Extensions());

        self::assertSame([], (new Keywords($loop, SchemaReferences::none()))->values('maxLength'));
    }

    public function testKeepsTheLinksOfAnAllOfBranchUpToTheSchemaItLeadsBackTo(): void
    {
        $branch = $this->linked('/A/allOf/0', '#/C', ['maxLength' => 5]);
        $c = $this->linked('/C', '#/A', ['minLength' => 2]);
        $a = new Schema(new SchemaLocation('api.yaml', '/A'), [], null, null, null, false, null, null, [], [], null, null, [$branch], [], [], null, [], new Extensions());
        $graph = new SchemaGraph(
            [new ResolvedSchema($a, 0, 'A', true), new ResolvedSchema($c, 0, 'C', true)],
            [(new ReferenceUse('#/C', $branch->location()))->key() => $c->location()->toString(), (new ReferenceUse('#/A', $c->location()))->key() => $a->location()->toString()],
        );
        $keywords = new Keywords($a, new SchemaReferences($graph));

        self::assertSame([5], $keywords->values('maxLength'));
        self::assertSame([2], $keywords->values('minLength'));
    }

    /**
     * @param array<string, JsonValue> $keywords
     */
    private function linked(string $pointer, string $ref, array $keywords): Schema
    {
        return new Schema(new SchemaLocation('api.yaml', $pointer), [], $ref, null, null, false, null, null, [], [], null, null, [], [], [], null, $keywords, new Extensions());
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

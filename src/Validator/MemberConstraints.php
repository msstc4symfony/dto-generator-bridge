<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The members of a `oneOf` or `anyOf` of scalars that the generator types as several values: Symfony's AtLeastOneOf
 * for `anyOf`, the runtime's ExactlyOneOf for `oneOf`, each member its Type and its own constraints.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class MemberConstraints
{
    private const SCALARS = [SchemaType::STRING, SchemaType::INTEGER, SchemaType::NUMBER, SchemaType::BOOLEAN];

    private ConstraintBuilder $builder;

    private SchemaReferences $references;

    private RuntimeConstraints $runtime;

    private TargetProfile $target;

    /** @var list<non-empty-string> */
    private array $groups;

    /**
     * @param list<non-empty-string> $groups the groups of the value, which a nested Sequentially needs too
     */
    public function __construct(ConstraintBuilder $builder, SchemaReferences $references, RuntimeConstraints $runtime, TargetProfile $target, array $groups)
    {
        $this->builder = $builder;
        $this->references = $references;
        $this->runtime = $runtime;
        $this->target = $target;
        $this->groups = $groups;
    }

    /**
     * @param TypeModel $value the type the generator gives the value
     *
     * @return list<ConstraintSpec>|null null when a member is no scalar of one place this can check: the members then
     *                                   go unchecked, with a warning only for the bridge's own keywords in them
     */
    public function of(Keywords $keywords, TypeModel $value, SchemaLocation $at): ?array
    {
        // A null beside a single other member only makes the value nullable, as Keywords reads it.
        $unions = array_values(array_filter($keywords->schemas(), fn (Schema $place): bool => count(array_filter(array_merge($place->oneOf(), $place->anyOf()), fn (Schema $member): bool => !$this->isNull($member))) > 1));
        if (!$this->isScalar($value) || count($unions) !== 1 || ($unions[0]->oneOf() !== [] && $unions[0]->anyOf() !== [])) {
            return null;
        }

        $union = $unions[0];
        $keyword = $union->oneOf() !== [] ? 'oneOf' : 'anyOf';
        $members = [];
        foreach ($union->oneOf() !== [] ? $union->oneOf() : $union->anyOf() as $member) {
            $place = $this->place($member);
            $type = $place instanceof Schema ? $this->scalarType($place) : null;
            if ($type === null) {
                return null;
            }

            if ($type === SchemaType::NULL) {
                continue;
            }

            $specs = $this->builder->subschema($keyword, $this->raw($place, $type), $union->location(), new MixedType(), true, $member->location());
            if ($specs === null) {
                return null;
            }

            $members[] = [$type, $specs];
        }

        $types = array_column($members, 0);
        // JSON Schema counts 5.0 as an integer too, which PHP's Type does not: such members are left unchecked.
        if (in_array(SchemaType::INTEGER, $types, true) && in_array(SchemaType::NUMBER, $types, true)) {
            return null;
        }

        return $this->constraints($keyword, $members, $union->location(), $at);
    }

    /**
     * @param 'oneOf'|'anyOf' $keyword
     * @param list<array{string, list<ConstraintSpec>}> $members
     *
     * @return list<ConstraintSpec>
     */
    private function constraints(string $keyword, array $members, SchemaLocation $union, SchemaLocation $at): array
    {
        $types = array_column($members, 0);
        $checked = array_filter($members, static fn (array $member): bool => count($member[1]) > 1);
        // On a target with union types the PHP type admits only the members' types and tells members of types apart;
        // an untyped property checks neither.
        $typed = $this->target->supports(Capability::from(Capability::UNION_TYPES));
        if ($checked === [] && $typed && ($keyword === 'anyOf' || count(array_unique($types)) === count($types))) {
            return [];
        }

        $inner = [];
        foreach ($members as [, $specs]) {
            // Members of one type need no Type, unless it is all a member checks; subschema() gives it first.
            $own = count(array_unique($types)) === 1 && count($specs) > 1 && $specs[0]->is('Type') ? array_slice($specs, 1) : $specs;
            $nested = count($own) === 1 ? [] : $this->builder->asNew($keyword, $own, $union);
            if ($nested === null) {
                return [];
            }

            // Symfony fixes the groups of a nested composite when it is built; the outer one does not pass its own on.
            $inner[] = count($own) === 1 ? $own[0] : (new ConstraintSpec('Sequentially', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$nested))]))->withGroups($this->groups);
        }

        $arguments = $this->builder->asNew($keyword, $inner, $union);
        if ($arguments === null) {
            return [];
        }

        $constraints = [AttributeArgument::named('constraints', ArgumentValue::listOf(...$arguments))];
        if ($keyword === 'anyOf') {
            return [new ConstraintSpec('AtLeastOneOf', $constraints)];
        }

        return $this->runtime->available('oneOf', $at) ? [ConstraintSpec::runtime('ExactlyOneOf', $constraints)] : [];
    }

    /**
     * Whether every value of the type is a PHP scalar, null aside: a member typed as a class, such as a date, or an
     * enum would fail the Type of its JSON type.
     */
    private function isScalar(TypeModel $value): bool
    {
        $value = $value instanceof NullableType ? $value->inner() : $value;
        foreach ($value instanceof UnionType ? $value->members() : [$value] as $member) {
            if (!$member instanceof ScalarType) {
                return false;
            }
        }

        return true;
    }

    private function isNull(Schema $member): bool
    {
        return $member->ref() === null && array_map(static fn (SchemaType $type): string => $type->value(), $member->types()) === [SchemaType::NULL];
    }

    /**
     * The one place of a member that says anything but annotations, behind its `$ref`; null when several do.
     */
    private function place(Schema $member): ?Schema
    {
        $saying = array_values(array_filter(
            (new Keywords($member, $this->references))->schemas(),
            static fn (Schema $place): bool => $place->types() !== [] || array_filter($place->keywordNames(), static fn (string $name): bool => !Subschema::annotates($name)) !== [] || $place->format() !== null || $place->enum() !== null,
        ));

        return count($saying) === 1 ? $saying[0] : null;
    }

    /**
     * The one JSON type of a member that is a scalar or null, and nothing more; null for any other.
     */
    private function scalarType(Schema $place): ?string
    {
        // A const without a type is of the const's type.
        $types = $place->types() !== [] ? array_map(static fn (SchemaType $type): string => $type->value(), $place->types()) : ($place->hasKeyword('const') ? [$this->typeOf($place->keyword('const'))] : []);

        // The bridge's own keywords need a value of their own; they stay in the warning about members.
        $claimed = array_filter(ClaimedConstraints::KEYWORDS, static fn (string $keyword): bool => $place->hasKeyword($keyword));
        $composed = $place->oneOf() !== [] || $place->anyOf() !== [] || $place->propertyNames() !== [] || $claimed !== [];
        if ($composed || count($types) !== 1 || !in_array($types[0], array_merge(self::SCALARS, [SchemaType::NULL]), true)) {
            return null;
        }

        return $types[0];
    }

    /**
     * @param JsonValue $value
     */
    private function typeOf($value): string
    {
        if (is_string($value)) {
            return SchemaType::STRING;
        }

        if (is_int($value)) {
            return SchemaType::INTEGER;
        }

        if (is_float($value)) {
            return SchemaType::NUMBER;
        }

        if (is_bool($value)) {
            return SchemaType::BOOLEAN;
        }

        // An array or an object, which no scalar member holds.
        return $value === null ? SchemaType::NULL : SchemaType::ARRAY;
    }

    /**
     * @return array<string, JsonValue>
     */
    private function raw(Schema $place, string $type): array
    {
        $raw = ['type' => $type];
        foreach ($place->keywordNames() as $name) {
            $raw[$name] = $place->keyword($name);
        }

        if ($place->format() !== null) {
            $raw['format'] = $place->format();
        }

        return $raw;
    }
}

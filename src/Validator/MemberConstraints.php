<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
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

    public function __construct(ConstraintBuilder $builder, SchemaReferences $references, RuntimeConstraints $runtime)
    {
        $this->builder = $builder;
        $this->references = $references;
        $this->runtime = $runtime;
    }

    /**
     * @return list<ConstraintSpec>|null null when a member is no scalar of one place this can check: the members then
     *                                   go unchecked, with a warning
     */
    public function of(Keywords $keywords, SchemaLocation $at): ?array
    {
        $unions = array_values(array_filter($keywords->schemas(), static fn (Schema $place): bool => $place->oneOf() !== [] || $place->anyOf() !== []));
        if ($keywords->unreachedMembers() === [] || count($unions) !== 1 || ($unions[0]->oneOf() !== [] && $unions[0]->anyOf() !== [])) {
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
        // An integer is a number too, so only members of types apart are told apart by the PHP type alone.
        $apart = count(array_unique($types)) === count($types) && (!in_array(SchemaType::INTEGER, $types, true) || !in_array(SchemaType::NUMBER, $types, true));
        $checked = array_filter($members, static fn (array $member): bool => count($member[1]) > 1);
        if ($checked === [] && ($keyword === 'anyOf' || $apart)) {
            return [];
        }

        $inner = [];
        foreach ($members as [$type, $specs]) {
            // Members of one type need no Type, unless it is all a member checks.
            $own = count(array_unique($types)) === 1 && count($specs) > 1 ? array_slice($specs, 1) : $specs;
            $nested = count($own) === 1 ? [] : $this->builder->asNew($keyword, $own, $union);
            if ($nested === null) {
                return [];
            }

            $inner[] = count($own) === 1 ? $own[0] : new ConstraintSpec('Sequentially', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$nested))]);
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
     * The one place of a member that says anything, behind its `$ref`; null when several do.
     */
    private function place(Schema $member): ?Schema
    {
        $saying = array_values(array_filter(
            (new Keywords($member, $this->references))->schemas(),
            static fn (Schema $place): bool => $place->types() !== [] || $place->keywords() !== [] || $place->format() !== null || $place->enum() !== null,
        ));

        return count($saying) === 1 ? $saying[0] : null;
    }

    /**
     * The one JSON type of a member that is a scalar or null, and nothing more; null for any other.
     */
    private function scalarType(Schema $place): ?string
    {
        $types = array_map(static fn (SchemaType $type): string => $type->value(), $place->types());
        // The bridge's own keywords need a value of their own; they stay in the warning about members.
        $claimed = array_filter(ClaimedConstraints::KEYWORDS, static fn (string $keyword): bool => $place->hasKeyword($keyword));
        $composed = $place->oneOf() !== [] || $place->anyOf() !== [] || $place->allOf() !== [] || $place->enum() !== null || $place->propertyNames() !== [] || $claimed !== [];
        if ($composed || count($types) !== 1 || !in_array($types[0], array_merge(self::SCALARS, [SchemaType::NULL]), true)) {
            return null;
        }

        return $types[0];
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

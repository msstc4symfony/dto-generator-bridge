<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The constraints of `prefixItems` and `unevaluatedItems`, which Symfony's Collection, Count and All check. The generated
 * type gives `items` to every element, the prefix's too.
 *
 * @internal
 */
final class TupleConstraints
{
    /** The keywords besides `prefixItems` and `items` that evaluate elements, which unevaluatedItems would have to follow. */
    private const EVALUATING = ['contains', 'if'];

    private ConstraintBuilder $builder;

    private SchemaReferences $references;

    private Diagnostics $diagnostics;

    /** @var list<non-empty-string> */
    private array $groups;

    /**
     * @param list<non-empty-string> $groups the groups of the value, which the elements of a prefix need too
     */
    public function __construct(ConstraintBuilder $builder, SchemaReferences $references, Diagnostics $diagnostics, array $groups)
    {
        $this->builder = $builder;
        $this->references = $references;
        $this->diagnostics = $diagnostics;
        $this->groups = $groups;
    }

    /**
     * Collection for each `prefixItems`, and for each `unevaluatedItems` the length `false` allows or All for a schema
     * that applies to every element.
     *
     * @return list<ConstraintSpec>
     */
    public function of(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if (!$value instanceof ListType) {
            $this->warnAboutValuesOfOtherTypes($keywords, $at);

            return [];
        }

        $itemized = $this->itemized($keywords);
        $collections = [];
        $rest = [];
        foreach ($keywords->schemas() as $place) {
            $collection = $place->hasKeyword('prefixItems') ? $this->prefix($place, $value->item(), $itemized) : null;
            if ($collection instanceof ConstraintSpec) {
                $collections[] = $collection;
            }

            if ($place->hasKeyword('unevaluatedItems')) {
                array_push($rest, ...$this->unevaluated($place, $value->item()));
            }
        }

        return array_merge($collections, $rest);
    }

    /**
     * Only an empty `prefixItems` and an `unevaluatedItems` that accepts everything have no effect on another value.
     */
    private function warnAboutValuesOfOtherTypes(Keywords $keywords, SchemaLocation $at): void
    {
        foreach (['prefixItems', 'unevaluatedItems'] as $keyword) {
            $effective = array_filter($keywords->values($keyword), static fn ($setting): bool => $setting !== [] && $setting !== true);
            if ($effective !== []) {
                $this->diagnostics->warning(sprintf('"%s" is checked on arrays only; it is not checked here.', $keyword), $at);
            }
        }
    }

    /**
     * Collection of the prefix's elements by index; null when none is checked, or the prefix is not, with a warning.
     *
     * @param bool $itemized whether `items` types every element, those of the prefix too
     */
    private function prefix(Schema $place, TypeModel $item, bool $itemized): ?ConstraintSpec
    {
        $at = $place->location();
        $entries = $place->keyword('prefixItems');
        if (!is_array($entries) || ($entries !== [] && !Json::isList($entries))) {
            $this->diagnostics->warning('"prefixItems" is checked only with a list of schemas; it is not checked.', $at);

            return null;
        }

        if ($itemized) {
            $this->diagnostics->warning('"prefixItems" is checked only without "items", which the generated type gives to every element; it is not checked.', $at);

            return null;
        }

        $fields = [];
        foreach ($entries as $index => $entry) {
            $specs = $this->builder->subschema('prefixItems', Json::value($entry), $at, $item, false);
            $inner = $specs === null ? null : $this->builder->asNew('prefixItems', $specs, $at);
            if ($inner === null) {
                return null;
            }

            if ($inner !== []) {
                $fields[$index] = $this->field($inner);
            }
        }

        return $fields === [] ? null : new ConstraintSpec('Collection', [
            AttributeArgument::named('fields', ArgumentValue::mapOf($fields)),
            AttributeArgument::named('allowExtraFields', ArgumentValue::literal(true)),
            AttributeArgument::named('allowMissingFields', ArgumentValue::literal(true)),
        ]);
    }

    /**
     * The constraints of one element of a prefix. Collection wraps them in Required of the Default group, so a value
     * of other groups gets Optional of its groups, or Symfony would skip them, or refuse a Collection outside Default.
     *
     * @param non-empty-list<ArgumentValue> $inner
     */
    private function field(array $inner): ArgumentValue
    {
        if ($this->groups === []) {
            return ArgumentValue::listOf(...$inner);
        }

        return (new ConstraintSpec('Optional', [AttributeArgument::positional(ArgumentValue::listOf(...$inner))]))->withGroups($this->groups)->toNewInstance();
    }

    /**
     * The constraints of one `unevaluatedItems`, from what its own schema and the subschemas it reaches evaluate
     * (2020-12), not a sibling in `allOf`. An `items`, or an `unevaluatedItems` other than false, there evaluates
     * every element.
     *
     * @return list<ConstraintSpec>
     */
    private function unevaluated(Schema $place, TypeModel $item): array
    {
        $at = $place->location();
        $setting = $place->keyword('unevaluatedItems');
        $scope = new Keywords($place, $this->references);
        if ($setting === true || $setting === [] || $this->itemized($scope) || $this->evaluatedInside($scope, $place)) {
            return [];
        }

        if ($this->evaluatesElsewhere($scope)) {
            $this->diagnostics->warning('"unevaluatedItems" is checked only without "contains", "if", "oneOf" and "anyOf" in its schema; it is not checked.', $at);

            return [];
        }

        $lengths = [0];
        foreach ($scope->values('prefixItems') as $prefix) {
            if (!is_array($prefix) || ($prefix !== [] && !Json::isList($prefix))) {
                $this->diagnostics->warning('"unevaluatedItems" is checked only beside a list in "prefixItems"; it is not checked.', $at);

                return [];
            }

            $lengths[] = count($prefix);
        }

        if ($setting === false) {
            return [new ConstraintSpec('Count', [AttributeArgument::named('max', ArgumentValue::literal(max($lengths)))])];
        }

        if ($scope->has('prefixItems')) {
            $this->diagnostics->warning('"unevaluatedItems" is checked beside "prefixItems" only when false; it is not checked.', $at);

            return [];
        }

        $specs = $this->builder->subschema('unevaluatedItems', $setting, $at, $item, false);

        return $specs === null ? [] : $this->builder->each($specs, $at);
    }

    private function itemized(Keywords $keywords): bool
    {
        foreach ($keywords->schemas() as $place) {
            if ($place->items() instanceof Schema) {
                return true;
            }
        }

        return false;
    }

    /**
     * An `unevaluatedItems` of a subschema evaluates the elements it accepts, every one unless it is false.
     */
    private function evaluatedInside(Keywords $scope, Schema $place): bool
    {
        foreach ($scope->schemas() as $inner) {
            if ($inner !== $place && $inner->hasKeyword('unevaluatedItems') && $inner->keyword('unevaluatedItems') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A union's members evaluate elements too, unless one alone holds values besides null and so is in the scope.
     */
    private function evaluatesElsewhere(Keywords $scope): bool
    {
        foreach (self::EVALUATING as $keyword) {
            if ($scope->has($keyword)) {
                return true;
            }
        }

        return $scope->unreachedMembers() !== [];
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The conditions (`if`, `then`, `else`), negations (`not`) and dependencies (`dependentSchemas`) a class can check:
 * schemas of `properties` and `required`, by the PHP names of its own properties. As with `dependentRequired`, one the
 * schema declaring its properties holds is left to the class of that schema, and any other one with an inherited
 * property is not checked.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * A branch of a condition: the wire names of `properties`, each with its schema, and the wire names `required` lists.
 *
 * @phpstan-type BranchShape array{list<array{string, JsonValue}>, list<string>}
 *
 * A branch with the keyword warnings name it by, and whether its `required` decides (it does not in `then`, `else` and
 * the schema of `dependentSchemas`, where a property with a default meets it anyway).
 * @phpstan-type NamedBranch array{non-empty-string, BranchShape, bool}
 *
 * @internal
 */
final class ClassConditions
{
    private ConstraintBuilder $builder;

    private Diagnostics $diagnostics;

    /** @var list<non-empty-string> */
    private array $groups;

    private ClassProperties $properties;

    /**
     * @param list<non-empty-string> $groups the groups of the class, which Property needs as a composite
     */
    public function __construct(ClassProperties $properties, ConstraintBuilder $builder, Diagnostics $diagnostics, array $groups)
    {
        $this->properties = $properties;
        $this->builder = $builder;
        $this->diagnostics = $diagnostics;
        $this->groups = $groups;
    }

    /**
     * @return list<ConstraintSpec>
     */
    public function of(Keywords $keywords): array
    {
        $conditions = [];
        foreach (ClaimedConstraints::conditionPlaces($keywords) as $place) {
            $condition = $this->properties->ofAncestor($place) ? null : $this->condition($place, $keywords);
            if ($condition instanceof ConstraintSpec) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    /**
     * Not for each `not` of the class; `not: false` forbids nothing.
     *
     * @return list<ConstraintSpec>
     */
    public function negations(Keywords $keywords): array
    {
        $negations = [];
        foreach ($keywords->schemas() as $place) {
            if (!$place->hasKeyword('not') || $place->keyword('not') === false || $this->properties->ofAncestor($place)) {
                continue;
            }

            $read = $this->read('not', $place->keyword('not'), $place);
            $negation = $read === null ? null : $this->negation('not', $read, $place, $keywords);
            if ($negation instanceof ConstraintSpec) {
                $negations[] = $negation;
            }
        }

        return $negations;
    }

    /**
     * For each property `dependentSchemas` names: IfThenElse that checks its schema when the property is set, Not when
     * the schema is false; a schema that checks nothing gets nothing.
     *
     * @return list<ConstraintSpec>
     */
    public function dependencies(Keywords $keywords): array
    {
        $dependencies = [];
        foreach ($keywords->schemas() as $place) {
            if (!$place->hasKeyword('dependentSchemas') || $this->properties->ofAncestor($place)) {
                continue;
            }

            $schemas = $place->keyword('dependentSchemas');
            if (!is_array($schemas) || ($schemas !== [] && Json::isList($schemas))) {
                $this->diagnostics->warning('"dependentSchemas" is checked only with an object of schemas; it is not checked.', $place->location());

                continue;
            }

            foreach ($schemas as $name => $schema) {
                $dependency = $this->dependency((string) $name, Json::value($schema), $place, $keywords);
                if ($dependency instanceof ConstraintSpec) {
                    $dependencies[] = $dependency;
                }
            }
        }

        return $dependencies;
    }

    /**
     * @param JsonValue $schema
     */
    private function dependency(string $name, $schema, Schema $place, Keywords $keywords): ?ConstraintSpec
    {
        $set = [[], [$name]];
        if ($schema === false) {
            return $this->negation('dependentSchemas', $set, $place, $keywords);
        }

        $read = $this->read('dependentSchemas', $schema, $place);
        if ($read === null) {
            return null;
        }

        $branches = [['dependentSchemas', $set, true], ['dependentSchemas', $read, false]];
        if (!$this->namesOwnProperties($branches, $place, $keywords, 'it') || $this->namesAlwaysSet($branches, $place, 'it')) {
            return null;
        }

        $checks = $this->checks('dependentSchemas', $read, $place);
        if ($checks === null) {
            return null;
        }

        return $this->builder->condition(['if' => $this->checks('dependentSchemas', $set, $place) ?? [], 'then' => $checks, 'else' => []], $place->location(), 'dependentSchemas');
    }

    /**
     * Not of a schema of the class.
     *
     * @param 'not'|'dependentSchemas' $keyword
     * @param BranchShape $read
     */
    private function negation(string $keyword, array $read, Schema $place, Keywords $keywords): ?ConstraintSpec
    {
        $branches = [[$keyword, $read, true]];
        if (!$this->namesOwnProperties($branches, $place, $keywords, 'it') || $this->namesAlwaysSet($branches, $place, 'it')) {
            return null;
        }

        $checks = $this->checks($keyword, $read, $place);
        $inner = $checks === null ? null : $this->builder->asNew($keyword, $checks, $place->location());
        if ($inner === null) {
            return null;
        }

        return ConstraintSpec::runtime('Not', $inner === [] ? [] : [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))]);
    }

    private function condition(Schema $place, Keywords $keywords): ?ConstraintSpec
    {
        // The first branch that is not checked leaves the others unread, so it gets the only warning.
        $if = $this->branch('if', $place);
        if ($if === null) {
            return null;
        }

        $then = $this->branch('then', $place);
        if ($then === null) {
            return null;
        }

        $else = $this->branch('else', $place);
        if ($else === null) {
            return null;
        }

        $branches = [['if', $if, true], ['then', $then, false], ['else', $else, false]];
        if (!$this->namesOwnProperties($branches, $place, $keywords, 'the condition') || $this->namesAlwaysSet($branches, $place, 'the condition')) {
            return null;
        }

        $ifChecks = $this->checks('if', $if, $place);
        if ($ifChecks === null) {
            return null;
        }

        $thenChecks = $this->checks('then', $then, $place);
        if ($thenChecks === null) {
            return null;
        }

        $elseChecks = $this->checks('else', $else, $place);
        if ($elseChecks === null) {
            return null;
        }

        return $this->builder->condition(['if' => $ifChecks, 'then' => $thenChecks, 'else' => $elseChecks], $place->location());
    }

    /**
     * @param 'if'|'then'|'else' $branch
     *
     * @return BranchShape|null none for a missing branch, null for one that is not checked
     */
    private function branch(string $branch, Schema $place): ?array
    {
        return $place->hasKeyword($branch) ? $this->read($branch, $place->keyword($branch), $place) : [[], []];
    }

    /**
     * @param non-empty-string $branch the keyword that holds the schema, as warnings name it
     * @param JsonValue $value
     *
     * @return BranchShape|null null for a schema that is not checked
     */
    private function read(string $branch, $value, Schema $place): ?array
    {
        if ($value === true) {
            return [[], []];
        }

        if (!is_array($value) || ($value !== [] && Json::isList($value))) {
            $this->diagnostics->warning(sprintf('"%s" is checked on a class only with a schema object or true; it is not checked.', $branch), $place->location());

            return null;
        }

        $properties = [];
        $required = [];
        foreach ($value as $name => $listed) {
            $name = (string) $name;
            $setting = Json::value($listed);
            if ($name === 'properties' && is_array($setting) && ($setting === [] || !Json::isList($setting))) {
                foreach ($setting as $property => $schema) {
                    $properties[] = [(string) $property, Json::value($schema)];
                }
            } elseif ($name === 'required' && is_array($setting) && Json::isList($setting) && array_filter($setting, 'is_string') === $setting) {
                $required = $setting;
            } elseif ($name === 'type' && in_array('object', is_array($setting) ? $setting : [$setting], true)) {
                // Every instance of the class is an object.
                continue;
            } elseif (!Subschema::annotates($name)) {
                $this->diagnostics->warning(sprintf('"%s" is checked on a class only with "properties" and "required"; with "%s" it is not checked.', $branch, $name), $place->location());

                return null;
            }
        }

        return [$properties, $required];
    }

    /**
     * @param list<NamedBranch> $branches
     * @param 'the condition'|'it' $subject what a warning says is not checked
     */
    private function namesOwnProperties(array $branches, Schema $place, Keywords $keywords, string $subject): bool
    {
        $named = [];
        foreach ($branches as [$branch, [$properties, $required]]) {
            foreach (array_merge(array_column($properties, 0), $required) as $name) {
                $named[] = [$name, $branch];
            }
        }

        $foreign = array_values(array_filter($named, fn (array $pair): bool => !$this->properties->find($pair[0]) instanceof PropertyModel));
        if ($foreign === []) {
            return true;
        }

        // The class generated from the schema that declares every property checks the condition itself.
        if ($this->properties->inherits() && array_diff(array_column($named, 0), $place->propertyNames()) === []) {
            return false;
        }

        [$name, $branch] = $foreign[0];
        $this->diagnostics->warning($this->properties->inherits() && $this->declares($keywords, $name)
            ? sprintf('"%s" names "%s", which this class inherits; %s is not checked: write it in the schema that declares its properties.', $branch, $name, $subject)
            : sprintf('"%s" names "%s", which is not a property of this class; %s is not checked.', $branch, $name, $subject), $place->location());

        return false;
    }

    /**
     * A property with a default is always set, as the DTO cannot tell a missing key from it: whether it is missing,
     * and what a missing one holds, decide a condition differently than the schema does. In `then` and `else`,
     * `required` is met anyway, as in the schema of `dependentSchemas`; in `if` and `not` it decides.
     *
     * @param list<NamedBranch> $branches
     * @param 'the condition'|'it' $subject
     */
    private function namesAlwaysSet(array $branches, Schema $place, string $subject): bool
    {
        foreach ($branches as [$branch, [$properties, $required], $decides]) {
            $names = array_merge(array_column($properties, 0), $decides ? $required : []);
            foreach ($names as $name) {
                $default = $this->property($name)->default();
                if ($default instanceof DefaultValue && $default->value() !== null) {
                    $this->diagnostics->warning(sprintf('"%s" names "%s", which has a default and is always set; %s is not checked.', $branch, $name, $subject), $place->location());

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Property for each property schema that checks something, then Present for `required`.
     *
     * @param non-empty-string $branch
     * @param BranchShape $read
     *
     * @return list<ConstraintSpec>|null
     */
    private function checks(string $branch, array $read, Schema $place): ?array
    {
        [$properties, $required] = $read;
        $at = $place->location();
        $checks = [];
        foreach ($properties as [$name, $schema]) {
            $property = $this->property($name);
            $specs = $this->builder->subschema($branch, $schema, $at, $property->type(), true);
            $inner = $specs === null ? null : $this->builder->asNew($branch, $specs, $at);
            if ($inner === null) {
                return null;
            }

            if ($inner !== []) {
                $checks[] = ConstraintSpec::runtime('Property', [
                    AttributeArgument::named('name', ArgumentValue::literal($property->name())),
                    AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner)),
                ])->withGroups($this->groups);
            }
        }

        if ($required !== []) {
            $names = array_map(fn (string $name): ArgumentValue => ArgumentValue::literal($this->property($name)->name()), array_unique($required));
            $checks[] = ConstraintSpec::runtime('Present', [AttributeArgument::named('properties', ArgumentValue::listOf(...$names))]);
        }

        return $checks;
    }

    /**
     * A property namesOwnProperties() found.
     */
    private function property(string $name): PropertyModel
    {
        $property = $this->properties->find($name);
        assert($property instanceof PropertyModel);

        return $property;
    }

    private function declares(Keywords $keywords, string $name): bool
    {
        foreach ($keywords->schemas() as $place) {
            if (in_array($name, $place->propertyNames(), true)) {
                return true;
            }
        }

        return false;
    }
}

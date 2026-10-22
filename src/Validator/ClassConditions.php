<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
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
 * A branch of a condition: the wire names of `properties`, each with its schema and where it is written, and the wire
 * names `required` lists.
 *
 * @phpstan-type BranchShape array{list<array{string, JsonValue, SchemaLocation}>, list<string>}
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
            $condition = $this->properties->ofAncestor($place) ? null : $this->condition($place);
            if ($condition instanceof ConstraintSpec) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    /**
     * ExactlyOneOf for a `oneOf` beside the properties of a class, which the generator does not type, and for an `anyOf`
     * Not of the Not of each member, as Symfony's AtLeastOneOf takes no class: each member a branch of `properties` and
     * `required`, as a condition's.
     *
     * @param 'oneOf'|'anyOf' $keyword
     *
     * @return list<ConstraintSpec>
     */
    public function alternatives(Keywords $keywords, string $keyword): array
    {
        $alternatives = [];
        foreach ($keywords->schemas() as $place) {
            $members = $keyword === 'oneOf' ? $place->oneOf() : $place->anyOf();
            if ($members === [] || $place->propertyNames() === [] || $place->discriminator() instanceof Discriminator || $this->properties->ofAncestor($place)) {
                continue;
            }

            $alternative = $this->alternative($keyword, $members, $place);
            if ($alternative instanceof ConstraintSpec) {
                $alternatives[] = $alternative;
            }
        }

        return $alternatives;
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

            $read = $this->read('not', $place->keyword('not'), $place, $place->location()->child('not'));
            $negation = $read === null ? null : $this->negation('not', $read, $place);
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
                $dependency = $this->dependency((string) $name, Json::value($schema), $place);
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
    private function dependency(string $name, $schema, Schema $place): ?ConstraintSpec
    {
        $set = [[], [$name]];
        if ($schema === false) {
            return $this->negation('dependentSchemas', $set, $place);
        }

        $read = $this->read('dependentSchemas', $schema, $place, $place->location()->child('dependentSchemas', $name));
        if ($read === null) {
            return null;
        }

        $branches = [['dependentSchemas', $set, true], ['dependentSchemas', $read, false]];
        if (!$this->namesOwnProperties($branches, $place, 'it') || $this->namesAlwaysSet($branches, $place, 'it')) {
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
    private function negation(string $keyword, array $read, Schema $place): ?ConstraintSpec
    {
        $branches = [[$keyword, $read, true]];
        if (!$this->namesOwnProperties($branches, $place, 'it') || $this->namesAlwaysSet($branches, $place, 'it')) {
            return null;
        }

        $checks = $this->checks($keyword, $read, $place);
        $inner = $checks === null ? null : $this->builder->asNew($keyword, $checks, $place->location());
        if ($inner === null) {
            return null;
        }

        return ConstraintSpec::runtime('Not', $inner === [] ? [] : [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))]);
    }

    /**
     * @param 'oneOf'|'anyOf' $keyword
     * @param non-empty-list<Schema> $members
     */
    private function alternative(string $keyword, array $members, Schema $place): ?ConstraintSpec
    {
        $reads = [];
        foreach ($members as $member) {
            $read = $this->member($keyword, $member);
            if ($read === null) {
                return null;
            }

            $reads[] = $read;
        }

        $branches = array_map(static fn (array $read): array => [$keyword, $read, true], $reads);
        if (!$this->namesOwnProperties($branches, $place, 'it') || $this->namesAlwaysSet($branches, $place, 'it')) {
            return null;
        }

        $at = $place->location()->child($keyword);
        $inner = [];
        foreach ($reads as $read) {
            $checks = $this->checks($keyword, $read, $place);
            if ($checks === null) {
                return null;
            }

            if ($checks === []) {
                $this->diagnostics->warning(sprintf('A member of "%s" checks nothing a class holds; "%s" is not checked.', $keyword, $keyword), $at);

                return null;
            }

            $whole = $keyword === 'anyOf' || count($checks) > 1;
            $nested = $whole ? $this->builder->asNew($keyword, $checks, $at) : [];
            if ($nested === null) {
                return null;
            }

            $composite = $keyword === 'anyOf' ? ConstraintSpec::runtime('Not', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$nested))]) : new ConstraintSpec('Sequentially', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$nested))]);
            // Symfony fixes the groups of a nested composite when it is built; the outer one does not pass its own on.
            $inner[] = $whole ? $composite->withGroups($this->groups) : $checks[0];
        }

        $arguments = $this->builder->asNew($keyword, $inner, $at);
        if ($arguments === null) {
            return null;
        }

        $constraints = [AttributeArgument::named('constraints', ArgumentValue::listOf(...$arguments))];

        return ConstraintSpec::runtime($keyword === 'anyOf' ? 'Not' : 'ExactlyOneOf', $constraints);
    }

    /**
     * A member as a branch: an inline object of `properties`, each a schema of one value, and `required`.
     *
     * @param 'oneOf'|'anyOf' $keyword
     *
     * @return BranchShape|null
     */
    private function member(string $keyword, Schema $member): ?array
    {
        $types = array_map(static fn (SchemaType $type): string => $type->value(), $member->types());
        $plain = $member->ref() === null && $member->enum() === null && array_diff($types, ['object']) === []
            && $member->allOf() === [] && $member->oneOf() === [] && $member->anyOf() === [] && !$member->discriminator() instanceof Discriminator
            && !$member->items() instanceof Schema && !$member->additionalProperties() instanceof Schema
            && array_filter($member->keywordNames(), static fn (string $name): bool => !Subschema::annotates($name)) === [];
        $properties = [];
        foreach ($plain ? $member->propertyNames() : [] as $name) {
            $schema = $member->requireProperty($name);
            $flat = RawSchema::flat($schema);
            if ($flat === null) {
                $plain = false;

                break;
            }

            $properties[] = [$name, $flat, $schema->location()];
        }

        if (!$plain) {
            $this->diagnostics->warning(sprintf('"%s" is checked on a class only with members of "properties" that check one value each and "required"; it is not checked.', $keyword), $member->location());

            return null;
        }

        return [$properties, $member->required()];
    }

    private function condition(Schema $place): ?ConstraintSpec
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
        if (!$this->namesOwnProperties($branches, $place, 'the condition') || $this->namesAlwaysSet($branches, $place, 'the condition')) {
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
        return $place->hasKeyword($branch) ? $this->read($branch, $place->keyword($branch), $place, $place->location()->child($branch)) : [[], []];
    }

    /**
     * @param non-empty-string $branch the keyword that holds the schema, as warnings name it
     * @param JsonValue $value
     * @param SchemaLocation $written where the schema is
     *
     * @return BranchShape|null null for a schema that is not checked
     */
    private function read(string $branch, $value, Schema $place, SchemaLocation $written): ?array
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
                    $properties[] = [(string) $property, Json::value($schema), $written->child('properties', (string) $property)];
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
    private function namesOwnProperties(array $branches, Schema $place, string $subject): bool
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

        [$name, $branch] = $foreign[0];
        $this->diagnostics->warning(sprintf('"%s" names "%s", which is not a property of this class; %s is not checked.', $branch, $name, $subject), $place->location());

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
        foreach ($properties as [$name, $schema, $written]) {
            $property = $this->property($name);
            $specs = $this->builder->subschema($branch, $schema, $at, $property->type(), true, $written);
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
}

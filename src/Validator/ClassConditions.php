<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The conditions (`if`, `then`, `else`) a class can check: branches of `properties` and `required`, by the PHP names of
 * its own properties. As with `dependentRequired`, a condition the schema declaring its properties holds is left to the
 * class of that schema, and any other one with an inherited property is not checked.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * A branch of a condition: the wire names of `properties`, each with its schema, and the wire names `required` lists.
 *
 * @phpstan-type BranchShape array{list<array{string, JsonValue}>, list<string>}
 */
final class ClassConditions
{
    private ConstraintBuilder $builder;

    private Diagnostics $diagnostics;

    /** @var list<non-empty-string> */
    private array $groups;

    /** @var array<string, PropertyModel> by wire name */
    private array $own = [];

    private bool $inherits;

    /**
     * @param list<non-empty-string> $groups the groups of the class, which Property needs as a composite
     */
    public function __construct(ClassModel $class, ConstraintBuilder $builder, Diagnostics $diagnostics, array $groups)
    {
        $this->builder = $builder;
        $this->diagnostics = $diagnostics;
        $this->groups = $groups;
        foreach ($class->properties() as $property) {
            if (!$property->isAdditionalProperties()) {
                $this->own[$property->wireName()] = $property;
            }
        }

        $this->inherits = $class->parent() instanceof ClassName;
    }

    /**
     * @return list<ConstraintSpec>
     */
    public function of(Keywords $keywords): array
    {
        $conditions = [];
        foreach (ConstraintBuilder::conditionPlaces($keywords) as $place) {
            $condition = $this->condition($place, $keywords);
            if ($condition instanceof ConstraintSpec) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    private function condition(Schema $place, Keywords $keywords): ?ConstraintSpec
    {
        // The first branch that is not checked leaves the others unread, so it gets the only warning.
        $if = $this->read('if', $place);
        if ($if === null) {
            return null;
        }

        $then = $this->read('then', $place);
        if ($then === null) {
            return null;
        }

        $else = $this->read('else', $place);
        if ($else === null) {
            return null;
        }

        $branches = ['if' => $if, 'then' => $then, 'else' => $else];
        if (!$this->namesOwnProperties($branches, $place, $keywords) || $this->namesAlwaysSet($branches, $place)) {
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
    private function read(string $branch, Schema $place): ?array
    {
        if (!$place->hasKeyword($branch)) {
            return [[], []];
        }

        $value = $place->keyword($branch);
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
            } elseif (!Subschema::annotates($name)) {
                $this->diagnostics->warning(sprintf('"%s" is checked on a class only with "properties" and "required"; with "%s" it is not checked.', $branch, $name), $place->location());

                return null;
            }
        }

        return [$properties, $required];
    }

    /**
     * @param array{if: BranchShape, then: BranchShape, else: BranchShape} $branches
     */
    private function namesOwnProperties(array $branches, Schema $place, Keywords $keywords): bool
    {
        $named = [];
        foreach ($branches as $branch => [$properties, $required]) {
            foreach (array_merge(array_column($properties, 0), $required) as $name) {
                $named[] = [$name, $branch];
            }
        }

        $foreign = array_values(array_filter($named, fn (array $pair): bool => !isset($this->own[$pair[0]])));
        if ($foreign === []) {
            return true;
        }

        // The class generated from the schema that declares every property checks the condition itself.
        if ($this->inherits && array_diff(array_column($named, 0), $place->propertyNames()) === []) {
            return false;
        }

        [$name, $branch] = $foreign[0];
        $this->diagnostics->warning($this->inherits && $this->declares($keywords, $name)
            ? sprintf('"%s" names "%s", which this class inherits; the condition is not checked: write it in the schema that declares its properties.', $branch, $name)
            : sprintf('"%s" names "%s", which is not a property of this class; the condition is not checked.', $branch, $name), $place->location());

        return false;
    }

    /**
     * A property with a default is always set, as the DTO cannot tell a missing key from it: whether it is missing,
     * and what a missing one holds, decide a condition differently than the schema does. In `then` and `else`,
     * `required` is met anyway.
     *
     * @param array{if: BranchShape, then: BranchShape, else: BranchShape} $branches
     */
    private function namesAlwaysSet(array $branches, Schema $place): bool
    {
        foreach ($branches as $branch => [$properties, $required]) {
            $names = array_merge(array_column($properties, 0), $branch === 'if' ? $required : []);
            foreach ($names as $name) {
                $default = $this->own[$name]->default();
                if ($default instanceof DefaultValue && $default->value() !== null) {
                    $this->diagnostics->warning(sprintf('"%s" names "%s", which has a default and is always set; the condition is not checked.', $branch, $name), $place->location());

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Property for each property schema that checks something, then Present for `required`.
     *
     * @param 'if'|'then'|'else' $branch
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
            $property = $this->own[$name];
            $specs = $this->builder->subschema($branch, $schema, $at, $property->type(), true);
            $inner = $specs === null ? null : $this->builder->asNew('if', $specs, $at);
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
            $names = array_map(fn (string $name): ArgumentValue => ArgumentValue::literal($this->own[$name]->name()), array_unique($required));
            $checks[] = ConstraintSpec::runtime('Present', [AttributeArgument::named('properties', ArgumentValue::listOf(...$names))]);
        }

        return $checks;
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

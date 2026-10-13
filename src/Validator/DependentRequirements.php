<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The pairs of `dependentRequired` a class can check, by the PHP names of its own properties. A class that extends
 * another does not know the PHP names of the inherited properties: a pair the schema declaring both properties lists
 * is left to the class of that schema; any other pair with an inherited property is not checked.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @internal
 */
final class DependentRequirements
{
    private Diagnostics $diagnostics;

    private SchemaLocation $at;

    /** @var array<string, PropertyModel> by wire name */
    private array $own = [];

    private bool $inherits;

    public function __construct(ClassModel $class, Diagnostics $diagnostics, SchemaLocation $at)
    {
        $this->diagnostics = $diagnostics;
        $this->at = $at;
        foreach ($class->properties() as $property) {
            if (!$property->isAdditionalProperties()) {
                $this->own[$property->wireName()] = $property;
            }
        }

        $this->inherits = $class->parent() instanceof ClassName;
    }

    /**
     * Every place's pairs together, as all of them apply.
     *
     * @return array<string, non-empty-list<string>> PHP name => the PHP names it needs
     */
    public function of(Keywords $keywords): array
    {
        $requirements = [];
        foreach ($keywords->schemas() as $place) {
            if (!$place->hasKeyword('dependentRequired')) {
                continue;
            }

            $value = $place->keyword('dependentRequired');
            if (!is_array($value) || ($value !== [] && Json::isList($value))) {
                $this->diagnostics->warning('"dependentRequired" must map property names to lists of names; it is not checked.', $this->at);

                continue;
            }

            foreach ($value as $trigger => $listed) {
                $trigger = (string) $trigger;
                $dependents = Json::value($listed);
                if (!$this->isNameList($dependents)) {
                    $this->diagnostics->warning(sprintf('"dependentRequired" must map property names to lists of names; "%s" is not checked.', $trigger), $this->at);

                    continue;
                }

                foreach ($dependents as $dependent) {
                    $pair = $this->pair($trigger, $dependent, $keywords);
                    if ($pair !== null && !in_array($pair[1], $requirements[$pair[0]] ?? [], true)) {
                        $requirements[$pair[0]][] = $pair[1];
                    }
                }
            }
        }

        return $requirements;
    }

    /**
     * @param JsonValue $dependents
     *
     * @phpstan-assert-if-true list<string> $dependents
     */
    private function isNameList($dependents): bool
    {
        return is_array($dependents) && Json::isList($dependents) && array_filter($dependents, 'is_string') === $dependents;
    }

    /**
     * @return array{string, string}|null the PHP names, or null when the class does not check the pair
     */
    private function pair(string $trigger, string $dependent, Keywords $keywords): ?array
    {
        $triggerProperty = $this->own[$trigger] ?? null;
        $dependentProperty = $this->own[$dependent] ?? null;
        if ($triggerProperty !== null && $dependentProperty !== null) {
            return $this->unlessAlwaysSet($triggerProperty, $dependentProperty);
        }

        if ($this->inherits && $this->leftToTheDeclaringClass($keywords, $trigger, $dependent)) {
            return null;
        }

        foreach ([$trigger, $dependent] as $name) {
            if (!isset($this->own[$name]) && (!$this->inherits || !$this->declares($keywords, $name))) {
                $this->diagnostics->warning(sprintf(
                    '"dependentRequired" names "%s", which is not a property of this class; "%s" needing "%s" is not checked.',
                    $name,
                    $trigger,
                    $dependent,
                ), $this->at);

                return null;
            }
        }

        $this->diagnostics->warning(sprintf(
            '"dependentRequired": "%s" needing "%s" is not checked, as the class inherits "%s"; write the pair in the schema that declares both.',
            $trigger,
            $dependent,
            $triggerProperty === null ? $trigger : $dependent,
        ), $this->at);

        return null;
    }

    /**
     * A trigger with a default is always set, as the DTO cannot tell a missing key from it.
     *
     * @return array{string, string}|null
     */
    private function unlessAlwaysSet(PropertyModel $trigger, PropertyModel $dependent): ?array
    {
        $default = $trigger->default();
        if (!$default instanceof DefaultValue || $default->value() === null) {
            return [$trigger->name(), $dependent->name()];
        }

        $this->diagnostics->warning(sprintf(
            '"dependentRequired": "%1$s" needing "%2$s" is not checked, as "%1$s" has a default and is always set.',
            $trigger->wireName(),
            $dependent->wireName(),
        ), $this->at);

        return null;
    }

    /**
     * Whether a schema that declares both properties lists the pair, so the class generated from it checks it.
     */
    private function leftToTheDeclaringClass(Keywords $keywords, string $trigger, string $dependent): bool
    {
        foreach ($keywords->schemas() as $place) {
            $declared = $place->propertyNames();
            $listed = $place->hasKeyword('dependentRequired') ? Json::value($place->keyword('dependentRequired')) : null;
            if (in_array($trigger, $declared, true) && in_array($dependent, $declared, true) && is_array($listed)
                && in_array($dependent, (array) ($listed[$trigger] ?? []), true)) {
                return true;
            }
        }

        return false;
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

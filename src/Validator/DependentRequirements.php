<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The pairs of `dependentRequired` a class can check, by the PHP names of its own properties. A class that extends
 * another does not know the PHP names of the inherited properties: a pair of two of them is left to the parent, whose
 * schema declares them; a pair of an own and an inherited one is not checked.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class DependentRequirements
{
    private Diagnostics $diagnostics;

    private SchemaLocation $at;

    /** @var array<string, string> wire name => PHP name */
    private array $names = [];

    private bool $inherits;

    public function __construct(ClassModel $class, Diagnostics $diagnostics, SchemaLocation $at)
    {
        $this->diagnostics = $diagnostics;
        $this->at = $at;
        foreach ($class->properties() as $property) {
            if (!$property->isAdditionalProperties()) {
                $this->names[$property->wireName()] = $property->name();
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
        foreach ($keywords->values('dependentRequired') as $value) {
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
                    $pair = $this->pair($trigger, $dependent);
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
     * @return array{string, string}|null the PHP names, or null when the class cannot check the pair
     */
    private function pair(string $trigger, string $dependent): ?array
    {
        $triggerName = $this->names[$trigger] ?? null;
        $dependentName = $this->names[$dependent] ?? null;
        if ($triggerName !== null && $dependentName !== null) {
            return [$triggerName, $dependentName];
        }

        if ($this->inherits && $triggerName === null && $dependentName === null) {
            return null;
        }

        $this->diagnostics->warning($triggerName === null
            ? sprintf('"dependentRequired" names "%1$s", which is not a property of this class; "%1$s" needing "%2$s" is not checked.', $trigger, $dependent)
            : sprintf('"dependentRequired" names "%s", which is not a property of this class; "%s" needing it is not checked.', $dependent, $trigger), $this->at);

        return null;
    }
}

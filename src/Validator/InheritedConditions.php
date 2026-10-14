<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The conditions the nearest ancestor checks itself, as written: one the class's own schema repeats word for word
 * is left to it, as Symfony gives the class the ancestor's constraints too, and would report each violation twice.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @internal
 */
final class InheritedConditions
{
    /** @var list<string> each serialized */
    private array $written = [];

    private function __construct()
    {
    }

    /**
     * When the ancestor writes no constraints, or in other groups, the class checks every condition itself.
     */
    public static function none(): self
    {
        return new self();
    }

    public static function of(Keywords $keywords, ClassProperties $properties): self
    {
        $conditions = new self();
        foreach ($keywords->schemas() as $place) {
            if (!$properties->ofAncestor($place)) {
                continue;
            }

            foreach (self::conditions($place) as $condition) {
                $conditions->written[] = serialize($condition);
            }
        }

        return $conditions;
    }

    /**
     * @param array<string, JsonValue> $condition the keywords of one condition: `if` with its branches, a `not`, or one
     *                                            entry of `dependentSchemas`
     */
    public function repeats(array $condition): bool
    {
        return in_array(serialize($condition), $this->written, true);
    }

    /**
     * @return list<array<string, JsonValue>>
     */
    private static function conditions(Schema $place): array
    {
        $conditions = [];
        if ($place->hasKeyword('if')) {
            $conditions[] = self::branches($place);
        }

        if ($place->hasKeyword('not')) {
            $conditions[] = ['not' => $place->keyword('not')];
        }

        $dependencies = $place->hasKeyword('dependentSchemas') ? $place->keyword('dependentSchemas') : null;
        foreach (is_array($dependencies) ? $dependencies : [] as $name => $schema) {
            $conditions[] = self::dependency((string) $name, Json::value($schema));
        }

        return $conditions;
    }

    /**
     * @return array<string, JsonValue>
     */
    public static function branches(Schema $place): array
    {
        $branches = [];
        foreach (['if', 'then', 'else'] as $branch) {
            if ($place->hasKeyword($branch)) {
                $branches[$branch] = $place->keyword($branch);
            }
        }

        return $branches;
    }

    /**
     * @param JsonValue $schema
     *
     * @return array<string, JsonValue>
     */
    public static function dependency(string $name, $schema): array
    {
        return ['dependentSchemas' => [$name => $schema]];
    }
}

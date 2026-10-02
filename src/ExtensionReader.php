<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The bridge's `x-` keys of one schema; a value of the wrong kind is reported and ignored.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ExtensionReader
{
    private Schema $schema;

    private Diagnostics $diagnostics;

    public function __construct(Schema $schema, Diagnostics $diagnostics)
    {
        $this->schema = $schema;
        $this->diagnostics = $diagnostics;
    }

    public function flag(string $key): bool
    {
        $flag = $this->value($key);
        if ($flag !== null && !is_bool($flag)) {
            $this->diagnostics->warning(sprintf('%s must be true or false; it is ignored.', $key), $this->schema->location());
        }

        return $flag === true;
    }

    /**
     * The distinct group names under the key; null when the schema does not set it.
     *
     * @return list<non-empty-string>|null
     */
    public function groups(string $key): ?array
    {
        $declared = $this->value($key);
        if ($declared === null) {
            return null;
        }

        if (!is_array($declared) || array_values($declared) !== $declared) {
            $this->diagnostics->warning(sprintf('%s must be a list of group names; the property gets no groups.', $key), $this->schema->location());

            return [];
        }

        $groups = [];
        foreach ($declared as $group) {
            if (!is_string($group) || $group === '') {
                $this->diagnostics->warning(sprintf('%s lists a value that is not a group name; it is left out.', $key), $this->schema->location());
            } elseif (!in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * @return JsonValue
     */
    private function value(string $key)
    {
        $extensions = $this->schema->extensions();

        return $extensions->has($key) ? $extensions->get($key) : null;
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * One entry of `patternProperties`: the pattern as PCRE and the schema of the properties whose names match it.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class PatternSchema
{
    public const ALSO_ADDITIONAL = '"additionalProperties" applies to the properties "patternProperties" matches too, as the generated type gives every undeclared property its type.';

    /** @var non-empty-string */
    private string $pcre;

    /** @var JsonValue */
    private $schema;

    private SchemaLocation $place;

    /** @var list<string> */
    private array $declared;

    /**
     * @param non-empty-string $pcre
     * @param JsonValue $schema
     * @param list<string> $declared the wire names of the properties the schema that holds the pattern declares
     */
    private function __construct(string $pcre, $schema, SchemaLocation $place, array $declared)
    {
        $this->pcre = $pcre;
        $this->schema = $schema;
        $this->place = $place;
        $this->declared = $declared;
    }

    /**
     * The entries of every place, each pattern PHP can run.
     *
     * @return list<self>
     */
    public static function allOf(Keywords $keywords, Diagnostics $diagnostics): array
    {
        $patterns = [];
        foreach ($keywords->schemas() as $place) {
            if (!$place->hasKeyword('patternProperties')) {
                continue;
            }

            $at = $place->location();
            $value = $place->keyword('patternProperties');
            if (!is_array($value) || ($value !== [] && Json::isList($value))) {
                $diagnostics->warning('"patternProperties" must map patterns to schemas; it is not checked.', $at);

                continue;
            }

            foreach ($value as $pattern => $schema) {
                $pcre = Pattern::toPcre((string) $pattern);
                if ($pcre === null) {
                    $diagnostics->warning(sprintf('"patternProperties" holds "%s", which is no regular expression PHP can run; it is not checked.', $pattern), $at);

                    continue;
                }

                $patterns[] = new self($pcre, Json::value($schema), $at, $place->propertyNames());
            }
        }

        return $patterns;
    }

    /**
     * @return non-empty-string
     */
    public function pcre(): string
    {
        return $this->pcre;
    }

    /**
     * @return JsonValue
     */
    public function schema()
    {
        return $this->schema;
    }

    /**
     * The schema that holds the pattern, where warnings go.
     */
    public function place(): SchemaLocation
    {
        return $this->place;
    }

    /**
     * Whether the schema that holds the pattern declares the property, so the class generated from it checks it.
     */
    public function declares(string $name): bool
    {
        return in_array($name, $this->declared, true);
    }

    public function matches(string $name): bool
    {
        return preg_match($this->pcre, $name) === 1;
    }
}

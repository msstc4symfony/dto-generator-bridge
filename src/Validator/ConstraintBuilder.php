<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * The constraints a value's schema and type call for (bridge spec §5.1), in the order of that table.
 */
final class ConstraintBuilder
{
    private const FORMATS = [
        'email' => ['Email', 'mode', 'html5'],
        'ipv4' => ['Ip', 'version', '4'],
        'ipv6' => ['Ip', 'version', '6'],
        'hostname' => ['Hostname', 'requireTld', false],
        'uuid' => ['Uuid', null, null],
    ];

    private SchemaReferences $references;

    private TargetProfile $target;

    private Diagnostics $diagnostics;

    public function __construct(SchemaReferences $references, TargetProfile $target, Diagnostics $diagnostics)
    {
        $this->references = $references;
        $this->target = $target;
        $this->diagnostics = $diagnostics;
    }

    /**
     * @return list<Constraint>
     */
    public function build(Schema $schema, TypeModel $type, bool $notNull): array
    {
        $keywords = new Keywords($schema, $this->references->resolve($schema));
        $value = $type instanceof NullableType ? $type->inner() : $type;

        return array_merge(
            $notNull ? [new Constraint('NotNull')] : [],
            $this->strings($keywords),
            $this->numbers($keywords),
            $this->collections($keywords, $value),
            $this->choices($keywords),
            $this->constant($keywords, $schema),
            $this->format($keywords, $value),
            $this->nested($schema, $keywords, $value),
        );
    }

    /**
     * @return list<Constraint>
     */
    private function strings(Keywords $keywords): array
    {
        $constraints = [];
        $length = $this->bounds($keywords, 'minLength', 'maxLength');
        if ($length !== []) {
            $constraints[] = new Constraint('Length', $length);
        }

        $pattern = $keywords->get('pattern');
        if (is_string($pattern)) {
            $constraints[] = new Constraint('Regex', [AttributeArgument::named('pattern', ArgumentValue::literal('/' . str_replace('/', '\/', $pattern) . '/u'))]);
        }

        return $constraints;
    }

    /**
     * @return list<Constraint>
     */
    private function numbers(Keywords $keywords): array
    {
        $minimum = $keywords->number('minimum');
        $maximum = $keywords->number('maximum');
        $constraints = [];
        if ($minimum !== null && $maximum !== null) {
            $constraints[] = new Constraint('Range', [AttributeArgument::named('min', ArgumentValue::literal($minimum)), AttributeArgument::named('max', ArgumentValue::literal($maximum))]);
        } elseif ($minimum !== null) {
            $constraints[] = $this->comparison('GreaterThanOrEqual', $minimum);
        }

        $exclusiveMinimum = $keywords->number('exclusiveMinimum');
        if ($exclusiveMinimum !== null) {
            $constraints[] = $this->comparison('GreaterThan', $exclusiveMinimum);
        }

        if ($maximum !== null && $minimum === null) {
            $constraints[] = $this->comparison('LessThanOrEqual', $maximum);
        }

        $exclusiveMaximum = $keywords->number('exclusiveMaximum');
        if ($exclusiveMaximum !== null) {
            $constraints[] = $this->comparison('LessThan', $exclusiveMaximum);
        }

        $multipleOf = $keywords->number('multipleOf');
        if ($multipleOf !== null) {
            $constraints[] = $this->comparison('DivisibleBy', $multipleOf);
        }

        return $constraints;
    }

    /**
     * @return list<Constraint>
     */
    private function collections(Keywords $keywords, TypeModel $value): array
    {
        $constraints = [];
        $count = $value instanceof MapType ? $this->bounds($keywords, 'minProperties', 'maxProperties') : $this->bounds($keywords, 'minItems', 'maxItems');
        if ($count !== []) {
            $constraints[] = new Constraint('Count', $count);
        }

        if ($keywords->get('uniqueItems') === true) {
            $constraints[] = new Constraint('Unique');
        }

        return $constraints;
    }

    /**
     * Where the target has enums, the generated enum type limits the values; without, the property is a plain string
     * or int and only the constraint does.
     *
     * @return list<Constraint>
     */
    private function choices(Keywords $keywords): array
    {
        $enum = $keywords->enum();
        if ($enum === null || $this->target->supports(Capability::from(Capability::ENUMS))) {
            return [];
        }

        // The generator accepts only string or integer enums, which Choice compares as JSON does.
        $choices = array_map(static fn ($choice): ArgumentValue => ArgumentValue::literal(is_scalar($choice) ? $choice : null), $enum);

        return [new Constraint('Choice', [AttributeArgument::named('choices', ArgumentValue::listOf(...$choices))])];
    }

    /**
     * @return list<Constraint>
     */
    private function constant(Keywords $keywords, Schema $schema): array
    {
        if (!$keywords->has('const')) {
            return [];
        }

        $constant = $keywords->get('const');
        if ($constant === null) {
            return [new Constraint('IsNull')];
        }

        if (is_scalar($constant)) {
            return [$this->comparison('IdenticalTo', $constant)];
        }

        $this->diagnostics->warning('"const" with an array or object has no Symfony constraint; it is not checked.', $schema->location());

        return [];
    }

    /**
     * A format the config maps to a class gives the property that type, and the class validates itself.
     *
     * @return list<Constraint>
     */
    private function format(Keywords $keywords, TypeModel $value): array
    {
        $format = $keywords->format();
        if (!$value instanceof ScalarType || $format === null || !isset(self::FORMATS[$format])) {
            return [];
        }

        [$name, $option, $setting] = self::FORMATS[$format];

        return [new Constraint($name, $option === null ? [] : [AttributeArgument::named($option, ArgumentValue::literal($setting))])];
    }

    /**
     * @return list<Constraint>
     */
    private function nested(Schema $schema, Keywords $keywords, TypeModel $value): array
    {
        if ($keywords->describesObject()) {
            return [new Constraint('Valid')];
        }

        $resolved = $this->references->resolve($schema);
        if ($value instanceof ListType) {
            [$item, $itemSchema] = [$value->item(), $resolved->items()];
        } elseif ($value instanceof MapType) {
            [$item, $itemSchema] = [$value->value(), $resolved->additionalProperties()];
        } else {
            return [];
        }

        if (!$itemSchema instanceof Schema) {
            return [];
        }

        // Valid traverses arrays itself, so a collection of objects needs it once, on the property.
        if ((new Keywords($itemSchema, $this->references->resolve($itemSchema)))->describesObject()) {
            return [new Constraint('Valid')];
        }

        $inner = array_map(static fn (Constraint $constraint): ArgumentValue => $constraint->toNewInstance(), $this->build($itemSchema, $item, false));

        return $inner === [] ? [] : [new Constraint('All', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))])];
    }

    /**
     * @return list<AttributeArgument>
     */
    private function bounds(Keywords $keywords, string $minimum, string $maximum): array
    {
        $bounds = [];
        foreach (['min' => $minimum, 'max' => $maximum] as $name => $keyword) {
            $bound = $keywords->get($keyword);
            if (is_int($bound)) {
                $bounds[] = AttributeArgument::named($name, ArgumentValue::literal($bound));
            }
        }

        return $bounds;
    }

    /**
     * @param non-empty-string $name
     * @param bool|float|int|string $value
     */
    private function comparison(string $name, $value): Constraint
    {
        return new Constraint($name, [AttributeArgument::named('value', ArgumentValue::literal($value))]);
    }
}

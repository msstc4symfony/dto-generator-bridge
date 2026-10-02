<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\ComponentGate;
use Msstc4Symfony\DtoGeneratorBridge\ExtensionReader;
use Msstc4Symfony\DtoGeneratorBridge\Settings;
use Msstc4Symfony\DtoGeneratorBridge\SymfonyVersion;

/**
 * Symfony Validator constraints for every property (bridge spec §5); ComponentGate decides whether to write any.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ValidatorEnricher implements PropertyEnricher
{
    private Settings $settings;

    private ComponentGate $gate;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->gate = ComponentGate::validator($settings);
    }

    public function enrichProperty(PropertyContext $context): array
    {
        $schema = $context->schema();
        $version = $this->gate->version($context->packages(), $context->target(), $context->diagnostics(), $schema->location());
        $extensions = new ExtensionReader($schema, $context->diagnostics());
        if (!$version instanceof SymfonyVersion || $extensions->flag('x-validator-skip')) {
            return [];
        }

        $property = $context->property();
        $diagnostics = $context->diagnostics();
        $builder = new ConstraintBuilder($context->references(), $context->target(), $diagnostics);
        $groups = $this->groups($extensions);

        // A required property is non-nullable, except a mixed one (null among its values): the generator makes a
        // nullable property optional otherwise.
        $constraints = array_merge(
            $property->isRequired() && !$property->type() instanceof MixedType ? [new ConstraintSpec('NotNull')] : [],
            $builder->build($schema, $property->type()),
        );

        return array_map(
            static fn (ConstraintSpec $constraint): AttributeModel => $constraint->withGroups($groups)->toAttribute(),
            $constraints,
        );
    }

    /**
     * Constraints with groups leave the Default group, so validate($dto) would skip them: Default is added unless the
     * schema says x-validator-groups-exclusive.
     *
     * @return list<non-empty-string>
     */
    private function groups(ExtensionReader $extensions): array
    {
        $groups = $extensions->groups('x-validator-groups') ?? $this->settings->groups();
        $exclusive = $extensions->flag('x-validator-groups-exclusive');
        if ($groups === [] || $exclusive || in_array('Default', $groups, true)) {
            return $groups;
        }

        $groups[] = 'Default';

        return $groups;
    }
}

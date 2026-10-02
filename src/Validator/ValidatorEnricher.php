<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Settings;
use Msstc4Symfony\DtoGeneratorBridge\SymfonyVersion;

/**
 * Symfony Validator constraints for every property (bridge spec §5). Whether to write any is decided once per run,
 * on the first property, which also carries the warnings about that decision.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ValidatorEnricher implements PropertyEnricher
{
    private const COMPONENT = 'symfony/validator';

    private Settings $settings;

    private ?bool $writes = null;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function enrichProperty(PropertyContext $context): array
    {
        $schema = $context->schema();
        if (!$this->writes($context) || $this->extension($schema, 'x-validator-skip') === true) {
            return [];
        }

        // The generator makes a nullable property optional, so a required one is never null.
        $property = $context->property();
        $builder = new ConstraintBuilder($context->references(), $context->target(), $context->diagnostics());
        $groups = $this->groups($schema);

        return array_map(
            static fn (Constraint $constraint): AttributeModel => $constraint->withGroups($groups)->toAttribute(),
            $builder->build($schema, $property->type(), $property->isRequired()),
        );
    }

    private function writes(PropertyContext $context): bool
    {
        $this->writes ??= $this->decide($context);

        return $this->writes;
    }

    private function decide(PropertyContext $context): bool
    {
        $packages = $context->packages();
        $target = $context->target();
        $at = $context->schema()->location();
        $diagnostics = $context->diagnostics();
        $setting = $this->settings->validator();
        $installed = $packages->has(self::COMPONENT);
        if ($setting === false || $target->metadata()->isNone() || ($setting === null && !$installed)) {
            return false;
        }

        if (!$installed) {
            $diagnostics->warning('symfony/validator is not installed, but the bridge writes its constraints because extensionConfig.symfony.validator is true.', $at);
        }

        $version = SymfonyVersion::resolve($this->settings, $packages, self::COMPONENT);
        if (!$version->isSupported()) {
            $diagnostics->warning(sprintf('The bridge writes constraints for symfony/validator %s or newer, the project has %s; none are written.', SymfonyVersion::MINIMUM, $version->toString()), $at);

            return false;
        }

        if (!$target->metadata()->isAnnotations()) {
            return true;
        }

        if ($version->isAtLeast(SymfonyVersion::fromString('7.0'))) {
            $message = sprintf('symfony/validator %s reads no annotations, and PHP %s has no attributes; no constraints are written.', $version->toString(), $target->php()->toString());
            $target->isStrict() ? $diagnostics->error($message, $at) : $diagnostics->warning($message, $at);

            return false;
        }

        if (!$packages->has('doctrine/annotations')) {
            $diagnostics->warning('symfony/validator reads annotations through doctrine/annotations, which the project does not install.', $at);
        }

        return true;
    }

    /**
     * Constraints with groups leave the Default group, so validate($dto) would skip them: Default is added unless the
     * schema says x-validator-groups-exclusive.
     *
     * @return list<non-empty-string>
     */
    private function groups(Schema $schema): array
    {
        $declared = $this->extension($schema, 'x-validator-groups');
        $groups = is_array($declared) ? [] : $this->settings->groups();
        foreach (is_array($declared) ? $declared : [] as $group) {
            if (is_string($group) && $group !== '') {
                $groups[] = $group;
            }
        }
        if ($groups === [] || $this->extension($schema, 'x-validator-groups-exclusive') === true || in_array('Default', $groups, true)) {
            return $groups;
        }

        $groups[] = 'Default';

        return $groups;
    }

    /**
     * @return JsonValue
     */
    private function extension(Schema $schema, string $key)
    {
        $extensions = $schema->extensions();

        return $extensions->has($key) ? $extensions->get($key) : null;
    }
}

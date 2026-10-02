<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge;

use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * Whether the bridge writes for a Symfony component at all, and for which version (bridge spec §5.4, §5.5). Decided once
 * per run; the warnings about it point at the root of the first document.
 */
final class ComponentGate
{
    private Settings $settings;

    /** @var 'validator'|'serializer' */
    private string $component;

    /** @var 'constraints'|'attributes' */
    private string $writes;

    private bool $decided = false;

    private ?SymfonyVersion $version = null;

    /**
     * @param 'validator'|'serializer' $component
     * @param 'constraints'|'attributes' $writes
     */
    private function __construct(Settings $settings, string $component, string $writes)
    {
        $this->settings = $settings;
        $this->component = $component;
        $this->writes = $writes;
    }

    public static function validator(Settings $settings): self
    {
        return new self($settings, 'validator', 'constraints');
    }

    public static function serializer(Settings $settings): self
    {
        return new self($settings, 'serializer', 'attributes');
    }

    /**
     * The version to write for; null when the bridge writes nothing for the component.
     */
    public function version(InstalledPackages $packages, TargetProfile $target, Diagnostics $diagnostics, SchemaLocation $at): ?SymfonyVersion
    {
        if (!$this->decided) {
            $this->decided = true;
            $this->version = $this->decide($packages, $target, $diagnostics, new SchemaLocation($at->file()));
        }

        return $this->version;
    }

    private function decide(InstalledPackages $packages, TargetProfile $target, Diagnostics $diagnostics, SchemaLocation $at): ?SymfonyVersion
    {
        $package = 'symfony/' . $this->component;
        $setting = $this->component === 'validator' ? $this->settings->validator() : $this->settings->serializer();
        $installed = $packages->has($package);
        if ($setting === false || $target->metadata()->isNone() || ($setting === null && !$installed)) {
            return null;
        }

        if (!$installed) {
            $diagnostics->warning(sprintf(
                '%s is not installed, but the bridge writes its %s because extensionConfig.symfony.%s is true.',
                $package,
                $this->writes,
                $this->component,
            ), $at);
        }

        $annotations = $target->metadata()->isAnnotations();
        $version = SymfonyVersion::resolve($this->settings, $packages, $package, $annotations);
        if (!$version->isSupported()) {
            $diagnostics->warning(sprintf(
                'The bridge writes %s for %s %s or newer, the project has %s; none are written.',
                $this->writes,
                $package,
                SymfonyVersion::MINIMUM,
                $version->toString(),
            ), $at);

            return null;
        }

        if (!$annotations) {
            return $version;
        }

        if ($version->isAtLeast(SymfonyVersion::fromString('7.0'))) {
            $message = sprintf(
                '%s %s reads no annotations, %s; no %s are written.',
                $package,
                $version->toString(),
                $target->supports(Capability::from(Capability::ATTRIBUTES))
                    ? 'which target.metadata asks for'
                    : sprintf('and PHP %s has no attributes', $target->php()->toString()),
                $this->writes,
            );
            $target->isStrict() ? $diagnostics->error($message, $at) : $diagnostics->warning($message, $at);

            return null;
        }

        if (!$packages->has('doctrine/annotations')) {
            $diagnostics->warning(sprintf('%s reads annotations through doctrine/annotations, which the project does not install.', $package), $at);
        }

        return $version;
    }
}

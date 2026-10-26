<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * Whether the project has the runtime constraints: generated code naming a missing class fails only when validated.
 *
 * @internal
 */
final class RuntimeConstraints
{
    private const PACKAGE = 'msstc4symfony/dto-generator-bridge-runtime';

    /** The release that brought the constraints. */
    private const MINIMUM = '1.1';

    /** The keywords whose constraints came later. */
    /** An anyOf here is one beside a class's properties, which Not checks; one of values takes Symfony's AtLeastOneOf. */
    private const MINIMUM_OF = ['not' => '1.2', 'dependentSchemas' => '1.2', 'anyOf' => '1.2', 'oneOf' => '1.3'];

    private InstalledPackages $packages;

    private Diagnostics $diagnostics;

    public function __construct(InstalledPackages $packages, Diagnostics $diagnostics)
    {
        $this->packages = $packages;
        $this->diagnostics = $diagnostics;
    }

    /**
     * What else the project installs, which other constraints may need.
     */
    public function packages(): InstalledPackages
    {
        return $this->packages;
    }

    /**
     * @param non-empty-string $keyword
     */
    public function available(string $keyword, SchemaLocation $at): bool
    {
        $minimum = self::MINIMUM_OF[$keyword] ?? self::MINIMUM;
        if ($this->installed($minimum)) {
            return true;
        }

        $this->diagnostics->warning(sprintf(
            '"%s" is checked by %s %s or newer, which the project does not install; it is not checked.',
            $keyword,
            self::PACKAGE,
            $minimum,
        ), $at);

        return false;
    }

    /**
     * A branch (dev-main) counts as new: it is what a project tries before a release.
     */
    private function installed(string $minimum): bool
    {
        $version = $this->packages->version(self::PACKAGE);
        if ($version === null) {
            return false;
        }

        if (preg_match('/^v?(\d+)\.(\d+)/', $version, $match) !== 1) {
            return true;
        }

        return version_compare($match[1] . '.' . $match[2], $minimum, '>=');
    }
}

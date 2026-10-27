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
     * @param non-empty-string $keyword
     */
    public function available(string $keyword, SchemaLocation $at): bool
    {
        $minimum = self::MINIMUM_OF[$keyword] ?? self::MINIMUM;
        if (PackageVersion::atLeast($this->packages, self::PACKAGE, $minimum)) {
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
}

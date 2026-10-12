<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\Build;

use RuntimeException;

/**
 * The DTOs of the build mode could not be generated: the container is not built without them.
 *
 * @api
 */
final class GenerationFailed extends RuntimeException
{
}

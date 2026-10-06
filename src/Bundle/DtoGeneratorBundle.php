<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The `dto-generator:generate` command and the optional warmup check in a Symfony application (bridge spec §7). The
 * generator itself needs no bundle: the Composer plugin and the core's CLI work without it.
 */
final class DtoGeneratorBundle extends Bundle
{
}

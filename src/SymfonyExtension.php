<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use Msstc4Symfony\DtoGeneratorBridge\Validator\ValidatorEnricher;

/**
 * The bridge as the generator sees it (bridge spec §3): found through `extra.dto-generator.extensions`, configured
 * under `extensionConfig.symfony`.
 */
final class SymfonyExtension implements Extension
{
    public function name(): string
    {
        return 'symfony';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $settings = Settings::fromConfig($config);
        $registry->claimExtensionKeys('x-validator-*', 'x-serializer-*');
        $registry->addPropertyEnricher(new ValidatorEnricher($settings));
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use Msstc4Symfony\DtoGeneratorBridge\Serializer\SerializerEnricher;
use Msstc4Symfony\DtoGeneratorBridge\Validator\ValidatorEnricher;

/**
 * The bridge as the generator sees it (bridge spec §3): found through `extra.dto-generator.extensions`, configured
 * under `extensionConfig.symfony`.
 */
final class SymfonyExtension implements Extension
{
    /**
     * The keywords the generator calls unsupported and the runtime's constraints check; the bridge warns where it
     * cannot.
     */
    public const CHECKED_KEYWORDS = ['dependentRequired', 'propertyNames', 'contains', 'minContains', 'maxContains'];

    public function name(): string
    {
        return 'symfony';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $settings = Settings::fromConfig($config);
        $registry->claimExtensionKeys('x-validator-*', 'x-serializer-*');
        if ($settings->validator() !== false) {
            $registry->claimKeywords(...self::CHECKED_KEYWORDS);
        }

        $validator = new ValidatorEnricher($settings);
        $registry->addClassEnricher($validator);
        $registry->addPropertyEnricher($validator);

        $serializer = new SerializerEnricher($settings);
        $registry->addClassEnricher($serializer);
        $registry->addPropertyEnricher($serializer);
    }
}

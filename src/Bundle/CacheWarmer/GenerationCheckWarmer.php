<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\CacheWarmer;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Status;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Checks on cache warmup that the generated DTOs match their specs, and warns when not. It never writes them: on a
 * deploy the sources may be read-only, and generating belongs to the build.
 */
final class GenerationCheckWarmer implements CacheWarmerInterface
{
    private string $config;

    private LoggerInterface $logger;

    public function __construct(string $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * No native parameter types: Symfony 5.4 to 8 declare this method differently.
     *
     * @param string $cacheDir
     * @param string|null $buildDir
     *
     * @return list<string>
     */
    public function warmUp($cacheDir, $buildDir = null): array
    {
        $output = DtoGenerator::generator()(new Input($this->config, Mode::from(Mode::CHECK)));
        $status = $output->status()->value();
        if ($status === Status::OUT_OF_DATE) {
            $this->logger->warning(sprintf('The DTOs generated from %s are out of date; run bin/console dto-generator:generate.', $this->config));
        } elseif ($status !== Status::OK) {
            $errors = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->errors());
            $this->logger->warning(sprintf('dto-generator could not check %s: %s', $this->config, implode(' ', $errors)));
        }

        return [];
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Bundle;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps what was logged, for the warmup check.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> "<level>: <message>" */
    public array $records = [];

    /**
     * Untyped like psr/log 1, so that every major of the interface accepts it.
     *
     * @param string|Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = (is_string($level) ? $level : 'unknown') . ': ' . $message;
    }
}

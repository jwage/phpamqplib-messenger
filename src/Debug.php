<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Psr\Log\LoggerInterface;

/**
 * Wait/consume traces at debug level, gated on Symfony's kernel debug flag.
 *
 * The application logger is still present in production; these traces are not
 * written unless kernel.debug / APP_DEBUG is true. Retry and AMQP-exception
 * warning/info logs are unrelated and always follow the logger.
 */
final readonly class Debug
{
    public function __construct(
        private LoggerInterface|null $logger = null,
        private bool $enabled = false,
    ) {
    }

    /** @param array<array-key, mixed> $context */
    public function log(string $message, array $context = []): void
    {
        if ($this->logger === null || ! $this->enabled) {
            return;
        }

        $this->logger->debug($message, $context);
    }
}

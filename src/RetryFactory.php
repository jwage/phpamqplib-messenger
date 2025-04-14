<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Closure;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use Psr\Log\LoggerInterface;

class RetryFactory
{
    public function __construct(
        private LoggerInterface|null $logger = null,
    ) {
    }

    /** @param positive-int|0 $waitTime */
    public function retry(
        Closure|null $retry = null,
        int|null $retries = null,
        int|null $waitTime = null,
        bool|null $jitter = null,
    ): Retry {
        return (new Retry($retry, $retries, $waitTime, $jitter))
            ->setLogger($this->logger)
            ->catch([
                AMQPChannelClosedException::class,
                AMQPConnectionClosedException::class,
            ]);
    }
}

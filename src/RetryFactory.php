<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Closure;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
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
        int|null $retries = 10,
        int|null $waitTime = 2000,
        bool|null $jitter = true,
    ): Retry {
        return (new Retry($retry, $retries, $waitTime, $jitter))
            ->setLogger($this->logger)
            ->catch(AMQPExceptionInterface::class);
    }
}

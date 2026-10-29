<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Closure;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PhpAmqpLib\Exception\AMQPEmptyDeliveryTagException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPNotImplementedException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Psr\Log\LoggerInterface;

class RetryFactory
{
    public function __construct(
        private LoggerInterface|null $logger = null,
    ) {
    }

    /** @param positive-int|0 $waitTime */
    public function retry(
        Closure|null $run = null,
        int|null $retries = null,
        int|null $waitTime = null,
        bool|null $jitter = null,
    ): Retry {
        return (new Retry($run, $retries, $waitTime, $jitter))
            ->setLogger($this->logger)
            // AMQPRuntimeException covers closed channels and connections, invalid frames and
            // the generic write and read failures php-amqplib raises when a socket dies without
            // a clean close, such as "fwrite(): SSL: Connection timed out".
            ->catch([
                AMQPIOException::class,
                AMQPRuntimeException::class,
                AMQPTimeoutException::class,
            ])
            // A blocked connection will not clear within the retry window, and the other two
            // point at bugs rather than connection problems, so retrying would only delay them.
            ->except([
                AMQPConnectionBlockedException::class,
                AMQPEmptyDeliveryTagException::class,
                AMQPNotImplementedException::class,
            ]);
    }
}

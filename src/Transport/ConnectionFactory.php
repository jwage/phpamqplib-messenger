<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

class ConnectionFactory
{
    public function __construct(
        private DsnParser $dsnParser,
        private RetryFactory $retryFactory,
        private AmqpConnectionFactory $amqpConnectionFactory,
        private LoggerInterface|null $logger = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @throws InvalidArgumentException
     */
    public function fromDsn(
        #[SensitiveParameter]
        string $dsn,
        array $options = [],
    ): Connection {
        return new Connection(
            $this->retryFactory,
            $this->amqpConnectionFactory,
            $this->dsnParser->parseDsn($dsn, $options),
            $this->logger,
        );
    }
}

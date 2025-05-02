<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

class ConnectionFactory
{
    /** @var array<string, Connection> */
    private array $connections = [];

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
        $connectionConfig = $this->dsnParser->parseDsn($dsn, $options);

        $connectionHash = $connectionConfig->getHash();

        return $this->connections[$connectionHash] ??= new Connection(
            $this->retryFactory,
            $this->amqpConnectionFactory,
            $connectionConfig,
            $this->logger,
        );
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Override;
use SensitiveParameter;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function str_starts_with;

/** @implements TransportFactoryInterface<AMQPTransport> */
class AMQPTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private RetryFactory $retryFactory,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     *
     * @inheritDoc
     */
    #[Override]
    public function createTransport(
        #[SensitiveParameter]
        string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        unset($options['transport_name']);

        $connection = $this->connectionFactory->fromDsn($dsn, $options);

        return new AMQPTransport(
            retryFactory: $this->retryFactory,
            connection: $connection,
            serializer: $serializer,
        );
    }

    /** @inheritDoc */
    #[Override]
    public function supports(
        #[SensitiveParameter]
        string $dsn,
        array $options,
    ): bool {
        return str_starts_with($dsn, 'phpamqplib://') || str_starts_with($dsn, 'phpamqplibs://');
    }
}

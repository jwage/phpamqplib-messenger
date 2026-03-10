<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Override;
use SensitiveParameter;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function str_starts_with;

/** @implements TransportFactoryInterface<AmqpTransport> */
class AmqpTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @param array<array-key, mixed> $options
     *
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

        return new AmqpTransport(
            connection: $connection,
            serializer: $serializer,
        );
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @inheritDoc
     */
    #[Override]
    public function supports(
        #[SensitiveParameter]
        string $dsn,
        array $options,
    ): bool {
        return str_starts_with($dsn, 'phpamqplib://') || str_starts_with($dsn, 'phpamqplibs://');
    }
}

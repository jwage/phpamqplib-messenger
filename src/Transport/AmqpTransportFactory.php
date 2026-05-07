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

/**
 * Messenger transport factory for php-amqplib.
 *
 * Per-transport options (merged with DSN query and passed to {@see ConnectionFactory::fromDsn}):
 * - connection_reuse: optional override of bundle default `php_amqp_lib_messenger.connection_reuse`
 *   (`none`, `producer-consumer`, `all`).
 * - connection_role: `producer`, `consumer`, or `mixed` — used when `connection_reuse` is
 *   `producer-consumer` to decide which pool shares TCP connections.
 *
 * @implements TransportFactoryInterface<AMQPTransport>
 * @psalm-suppress TooManyTemplateParams
 */
class AmqpTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
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

        return new AmqpTransport(
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

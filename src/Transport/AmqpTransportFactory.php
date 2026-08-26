<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\EventListener\AmqpWorkerListener;
use Override;
use SensitiveParameter;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function is_string;
use function str_starts_with;

/**
 * @implements TransportFactoryInterface<AMQPTransport>
 * @psalm-suppress TooManyTemplateParams
 */
class AmqpTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private AmqpWorkerListener|null $workerListener = null,
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
        $transportName = isset($options['transport_name']) && is_string($options['transport_name'])
            ? $options['transport_name']
            : null;
        unset($options['transport_name']);

        $connection = $this->connectionFactory->fromDsn($dsn, $options);

        if (is_string($transportName) && $transportName !== '') {
            $this->workerListener?->addConnection($transportName, $connection);
        }

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

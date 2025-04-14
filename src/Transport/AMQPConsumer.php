<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Exception\TransportException;

use function array_shift;

class AMQPConsumer
{
    /** @var array<AMQPEnvelope> */
    private array $buffer = [];

    private bool $isConsuming = false;

    public function __construct(
        private Connection $connection,
        private ConnectionConfig $connectionConfig,
    ) {
    }

    /**
     * @return iterable<AMQPEnvelope>
     *
     * @throws AMQPExceptionInterface
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    public function get(string $queueName): iterable
    {
        $queueConfig = $this->connectionConfig->getQueueConfig($queueName);

        $channel = $this->connection->channel();

        if ($this->isConsuming === false) {
            $channel->basic_qos(
                prefetch_size: 0,
                prefetch_count: $queueConfig->prefetchCount,
                a_global: false,
            );

            $channel->basic_consume(
                queue: $queueName,
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: $this->callback(...),
            );

            $this->isConsuming = true;
        }

        if ($this->buffer === []) {
            try {
                $channel->wait(
                    allowed_methods: null,
                    non_blocking: false,
                    timeout: $queueConfig->waitTimeout,
                );
            } catch (AMQPExceptionInterface) {
                // When we get the timeout from wait(), do nothing
            }
        }

        $amqpEnvelope = array_shift($this->buffer);

        if ($amqpEnvelope === null) {
            return;
        }

        yield $amqpEnvelope;
    }

    public function callback(AMQPMessage $amqpMessage): void
    {
        $this->buffer[] = new AMQPEnvelope($amqpMessage);
    }
}

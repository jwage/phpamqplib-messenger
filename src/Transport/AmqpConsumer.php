<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Exception\TransportException;

class AmqpConsumer
{
    /** @var array<AmqpEnvelope> */
    private array $buffer = [];

    private string|null $consumerTag = null;

    public function __construct(
        private Connection $connection,
        private ConnectionConfig $connectionConfig,
    ) {
    }

    /**
     * @return iterable<AmqpEnvelope>
     *
     * @throws AMQPExceptionInterface
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    public function consume(string $queueName): iterable
    {
        $queueConfig = $this->connectionConfig->getQueueConfig($queueName);

        if ($this->consumerTag === null) {
            $this->start($queueConfig);
        }

        while ($this->connection->channel()->is_consuming()) {
            $buffer = $this->buffer;

            $this->buffer = [];

            yield from $buffer;

            try {
                $this->connection->channel()->wait(
                    allowed_methods: null,
                    non_blocking: false,
                    timeout: $queueConfig->waitTimeout,
                );
            } catch (AMQPTimeoutException) {
                break;
            }
        }
    }

    public function callback(AMQPMessage $amqpMessage): void
    {
        $this->buffer[] = new AmqpEnvelope($amqpMessage);
    }

    /** @throws TransportException */
    public function stop(): void
    {
        if ($this->consumerTag !== null) {
            try {
                $this->connection->channel()->basic_cancel(consumer_tag: $this->consumerTag);
            } catch (AMQPExceptionInterface $e) {
                throw new TransportException($e->getMessage(), 0, $e);
            }

            $this->consumerTag = null;
        }
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    private function start(QueueConfig $queueConfig): void
    {
        $this->connection->channel()->basic_qos(
            prefetch_size: 0,
            prefetch_count: $queueConfig->prefetchCount,
            a_global: false,
        );

        $this->consumerTag = $this->connection->channel()->basic_consume(
            queue: $queueConfig->name,
            consumer_tag: '',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: $this->callback(...),
        );
    }
}

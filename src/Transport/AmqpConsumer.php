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

    private bool $isConsuming = false;

    public function __construct(
        private Connection $connection,
        private ConnectionConfig $connectionConfig,
    ) {
    }

    /**
     * @return array<AmqpEnvelope>
     *
     * @throws AMQPExceptionInterface
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    public function get(string $queueName): array
    {
        $queueConfig = $this->connectionConfig->getQueueConfig($queueName);

        if ($this->isConsuming === false) {
            $this->startConsumer($queueConfig);
        }

        if ($this->buffer === []) {
            try {
                $this->connection->channel()->wait(
                    allowed_methods: null,
                    non_blocking: false,
                    timeout: $queueConfig->waitTimeout,
                );
            } catch (AMQPTimeoutException) {
                // When we get the timeout from wait(), do nothing and allow the consumer to continue
            }
        }

        $buffer = $this->buffer;

        $this->buffer = [];

        return $buffer;
    }

    public function callback(AMQPMessage $amqpMessage): void
    {
        $this->buffer[] = new AmqpEnvelope($amqpMessage);
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    private function startConsumer(QueueConfig $queueConfig): void
    {
        $this->connection->channel()->basic_qos(
            prefetch_size: 0,
            prefetch_count: $queueConfig->prefetchCount,
            a_global: false,
        );

        $this->connection->channel()->basic_consume(
            queue: $queueConfig->name,
            consumer_tag: '',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: $this->callback(...),
        );

        $this->isConsuming = true;
    }
}

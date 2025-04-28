<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\TransportException;

class AmqpConsumer
{
    /** @var array<AmqpEnvelope> */
    private array $buffer = [];

    private string|null $consumerTag = null;

    public function __construct(
        private Connection $connection,
        private ConnectionConfig $connectionConfig,
        private LoggerInterface|null $logger,
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

        $stop = false;

        while ($this->connection->channel()->is_consuming()) {
            try {
                $this->connection->channel()->wait(
                    allowed_methods: null,
                    non_blocking: false,
                    timeout: $queueConfig->waitTimeout,
                );
            // After we get the expected AMQPTimeoutException, we need to yield the buffer and break the loop.
            } catch (AMQPTimeoutException) {
                $stop = true;
            // If we get any AMQP exception here besides the expected AMQPTimeoutException,
            // we need to reconnect and break the loop immediately. The consumer will be restarted
            // on the next iteration that calls AmqpConsumer::consume().
            } catch (AMQPExceptionInterface $e) {
                $this->logger?->warning('AMQP exception occurred while waiting for messages: {message}', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);

                $this->connection->close();

                break;
            }

            $buffer = $this->buffer;

            $this->buffer = [];

            yield from $buffer;

            if ($stop) {
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
            } catch (AMQPExceptionInterface) {
                // do nothing
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

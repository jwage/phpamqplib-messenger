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

use function array_shift;
use function max;

class AmqpConsumer
{
    /** @var array<AmqpEnvelope> */
    private array $buffer = [];

    private string|null $consumerTag = null;

    private int|null $effectivePrefetchCount = null;

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
    public function consume(string $queueName, int|null $fetchSize = null): iterable
    {
        $queueConfig = $this->connectionConfig->getQueueConfig($queueName);

        try {
            $this->ensureStarted($queueName, $fetchSize);
        } catch (TransportException $e) {
            if (
                ! $this->connection->isRegisteredWithWaitCoordinator()
                && ! $this->connection->isExternalWaitEnabled()
            ) {
                throw $e;
            }

            $this->logger?->warning('AMQP exception occurred while starting consumer: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return;
        }

        if ($this->connection->isExternalWaitEnabled()) {
            if ($this->buffer === []) {
                $this->connection->drainConsumerChannel();
            }

            yield from $this->releaseBuffer($fetchSize);
        } elseif ($this->buffer !== []) {
            yield from $this->releaseBuffer($fetchSize);
        } elseif ($this->connection->isRegisteredWithWaitCoordinator()) {
            $this->connection->waitForDeliveries($this->connection->getWaitTimeout());

            yield from $this->releaseBuffer($fetchSize);
        } else {
            yield from $this->waitForChannelDeliveries($queueConfig, $fetchSize);
        }
    }

    /**
     * @return iterable<AmqpEnvelope>
     *
     * @throws AMQPExceptionInterface
     * @throws TransportException
     */
    private function waitForChannelDeliveries(QueueConfig $queueConfig, int|null $fetchSize): iterable
    {
        $stop = false;

        while ($this->connection->consumerChannel()->is_consuming()) {
            try {
                $this->connection->consumerChannel()->wait(
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

            yield from $this->releaseBuffer($fetchSize);

            if ($stop) {
                break;
            }
        }
    }

    /**
     * Subscribes to the queue if needed, without waiting for deliveries.
     *
     * @throws AMQPExceptionInterface
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    public function ensureStarted(string $queueName, int|null $fetchSize = null): void
    {
        $queueConfig = $this->connectionConfig->getQueueConfig($queueName);

        // Resolve the channel first. A dead cached channel must be discarded (and this
        // consumer invalidated) before we decide whether the tag is still valid.
        $this->connection->consumerChannel();

        $desiredPrefetch = max($fetchSize ?? $queueConfig->prefetchCount, $queueConfig->prefetchCount);

        if ($this->effectivePrefetchCount !== $desiredPrefetch || $this->consumerTag === null) {
            $this->connection->consumerChannel()->basic_qos(
                prefetch_size: 0,
                prefetch_count: $desiredPrefetch,
                a_global: false,
            );
            $this->effectivePrefetchCount = $desiredPrefetch;
        }

        if ($this->consumerTag === null) {
            $this->start($queueConfig);
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
                $this->connection->consumerChannel()->basic_cancel(consumer_tag: $this->consumerTag);
            } catch (AMQPExceptionInterface) {
                // do nothing
            }

            $this->consumerTag            = null;
            $this->effectivePrefetchCount = null;
        }
    }

    /**
     * Forgets the consumer registration without talking to the broker.
     *
     * Called when the channel this consumer was registered on has been discarded. Unlike
     * stop() this issues no basic_cancel: resolving a channel to cancel on would open and
     * cache a replacement channel, and the broker may not be readable at all (a blocked
     * connection stops being read, so waiting for basic.cancel-ok can hang). Dropping the
     * tag lets the next consume() re-register on the replacement channel.
     *
     * Buffered envelopes are dropped with it because their delivery tags belong to the
     * discarded channel and cannot be acknowledged on its replacement. The broker only
     * requeues those deliveries once that channel or the connection actually closes.
     */
    public function invalidate(): void
    {
        $this->consumerTag            = null;
        $this->effectivePrefetchCount = null;
        $this->buffer                 = [];
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    private function start(QueueConfig $queueConfig): void
    {
        $this->consumerTag = $this->connection->consumerChannel()->basic_consume(
            queue: $queueConfig->name,
            consumer_tag: '',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: $this->callback(...),
        );
    }

    /** @return iterable<AmqpEnvelope> */
    private function releaseBuffer(int|null $fetchSize): iterable
    {
        if ($fetchSize === null) {
            // Keep original snapshotting into local variable (for legacy code support)!
            // We do not use this approach when $fetchSize is used, because it will
            // not yield all items in case the caller breaks mid-iteration forced by the $fetchSize
            $buffer = $this->buffer;

            $this->buffer = [];

            yield from $buffer;
        } else {
            // Drain by shifting items off $this->buffer one-by-one rather than snapshotting it
            // into a local and clearing the instance buffer up-front. If the caller breaks
            // mid-iteration (e.g. AmqpReceiver honoring fetchSize), only the items already
            // yielded are removed; the rest remain on $this->buffer and are picked up by the
            // next consume() call instead of being silently dropped from PHP memory.
            while (($amqpEnvelope = array_shift($this->buffer)) !== null) {
                yield $amqpEnvelope;
            }
        }
    }
}

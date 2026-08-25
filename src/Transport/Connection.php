<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Closure;
use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

use function array_map;
use function array_merge;
use function array_shift;
use function array_sum;
use function array_unshift;
use function assert;
use function count;

class Connection
{
    private AMQPStreamConnection|null $connection = null;

    private AMQPChannel|null $channel = null;

    private AMQPChannel|null $consumerChannel = null;

    /** @var list<AMQPChannel> */
    private array $retiredPublisherChannels = [];

    /** @var array<string, AmqpConsumer> */
    private array $consumers = [];

    /** @var list<array{0: AMQPMessage, 1: string, 2: string}> */
    private array $batchMessages = [];

    private AMQPChannel|null $pendingBatchConfirmChannel = null;

    private bool $autoSetup;

    private bool $autoSetupDelay;

    public function __construct(
        private RetryFactory $retryFactory,
        private AmqpConnectionFactory $amqpConnectionFactory,
        private ConnectionConfig $connectionConfig,
        private LoggerInterface|null $logger = null,
    ) {
        $this->autoSetup      = $connectionConfig->autoSetup;
        $this->autoSetupDelay = $connectionConfig->delay->enabled && $connectionConfig->delay->autoSetup;
    }

    public function __destruct()
    {
        $this->invalidateConsumers();

        $this->connection      = null;
        $this->channel         = null;
        $this->consumerChannel = null;
        $this->consumers       = [];

        $this->pendingBatchConfirmChannel = null;
        $this->retiredPublisherChannels   = [];
    }

    public function getConfig(): ConnectionConfig
    {
        return $this->connectionConfig;
    }

    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    public function close(): void
    {
        try {
            // Closing the connection cancels its consumers, so do not issue a separate
            // basic_cancel first. Besides being redundant, that round trip can block on
            // an alarm-blocked connection or reconnect solely to cancel a stale tag.
            $this->invalidateConsumers();
            $this->connection?->close();
        } finally {
            $this->connection      = null;
            $this->channel         = null;
            $this->consumerChannel = null;
            $this->consumers       = [];

            $this->pendingBatchConfirmChannel = null;
            $this->retiredPublisherChannels   = [];
        }
    }

    /** @throws AMQPExceptionInterface */
    public function reconnect(): void
    {
        $this->forgetChannels();
        $this->connection?->reconnect();
    }

    /**
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    public function setup(): void
    {
        $this->setupExchangeAndQueues();

        if ($this->connectionConfig->delay->enabled) {
            try {
                $this->setupDelayExchange();
            } catch (AMQPExceptionInterface $e) {
                throw new TransportException($e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Sends an AMQP heartbeat frame if the heartbeat interval has elapsed.
     *
     * This prevents RabbitMQ from closing the connection during long message processing.
     *
     * @throws TransportException
     */
    public function keepalive(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            $this->connection->checkHeartBeat();
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Returns the channel reserved for publishing and topology operations.
     *
     * Consumers managed by this transport use a separate channel so publisher failure
     * and retirement cannot invalidate their delivery tags. Use consume() rather than
     * registering a consumer directly on this channel.
     *
     * @throws AMQPExceptionInterface
     * @throws TransportException
     */
    public function channel(): AMQPChannel
    {
        if ($this->connection !== null && ! $this->isConnected()) {
            $this->forgetChannels();
        }

        if ($this->channel === null) {
            $this->closeRetiredPublisherChannels();

            $channel = $this->retryWithReconnect(function (): AMQPChannel {
                $channel = $this->connection()->channel();

                if ($this->connectionConfig->confirmEnabled) {
                    $channel->confirm_select();
                    $channel->set_nack_handler(
                        static function (AMQPMessage $_message): never {
                            throw new PublisherNack('The broker negatively acknowledged a published message.');
                        },
                    );
                }

                return $channel;
            })->run();
            assert($channel instanceof AMQPChannel);

            $this->channel = $channel;
        }

        return $this->channel;
    }

    /**
     * Returns the channel reserved for transport-managed consumers.
     *
     * @internal AmqpConsumer owns registrations and local state on this channel.
     *
     * @throws TransportException
     */
    public function consumerChannel(): AMQPChannel
    {
        if ($this->connection !== null && ! $this->isConnected()) {
            $this->forgetChannels();
        }

        if ($this->consumerChannel !== null && ! $this->consumerChannel->is_open()) {
            $this->consumerChannel = null;
            $this->invalidateConsumers();
        }

        if ($this->consumerChannel === null) {
            $channel = $this->retryWithReconnect(
                fn (): AMQPChannel => $this->connection()->channel(),
            )->run();
            assert($channel instanceof AMQPChannel);

            $this->consumerChannel = $channel;
        }

        return $this->consumerChannel;
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
        if ($this->autoSetup) {
            $this->setupExchangeAndQueues();
        }

        return ($this->consumers[$queueName] ??= new AmqpConsumer($this, $this->connectionConfig, $this->logger))->consume($queueName, $fetchSize);
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    public function publish(
        string $body,
        array $headers = [],
        int $delayInMs = 0,
        int $batchSize = 1,
        AmqpStamp|null $amqpStamp = null,
    ): void {
        $isRetryAttempt     = $amqpStamp && $amqpStamp->isRetryAttempt();
        $shouldBatchPublish = $batchSize > 1 && $isRetryAttempt === false;

        // Do not accept another message into a batch whose publish outcome is still
        // waiting on confirms. Re-wait the original channel before mutating the buffer.
        if ($this->pendingBatchConfirmChannel !== null) {
            $this->flush();
        }

        // Messages already accepted into the batch must reach the broker before a newer
        // direct publish. If the retained batch still cannot flush, do not publish the
        // newer message out of order.
        if (! $shouldBatchPublish && $this->batchMessages !== []) {
            $this->flush();
        }

        if ($this->autoSetup) {
            $this->setupExchangeAndQueues();
        }

        $attributes = $amqpStamp?->getAttributes() ?? [];

        $amqpEnvelope = $this->createAMQPEnvelope($body, $attributes, $headers);

        $isDelayed  = $delayInMs > 0;
        $routingKey = $this->getRoutingKeyForMessage($amqpStamp);

        if ($isDelayed) {
            $publishRoutingKey = $this->connectionConfig->getDelayQueueName(
                $delayInMs,
                $routingKey,
                $isRetryAttempt,
            );

            if ($this->connectionConfig->delay->enabled) {
                $this->setupDelayExchangeAndQueue(
                    $delayInMs,
                    $routingKey,
                    $isRetryAttempt,
                );
            }
        } else {
            $publishRoutingKey = $routingKey;
        }

        $exchangeName = $isDelayed
            ? $this->connectionConfig->delay->exchange->name
            : $this->connectionConfig->exchange->name;

        /**
         * The original message may have been published in a batch and a retry will still have a
         * batch size defined but we should not batch publish when it is a retry attempt.
         */
        if ($shouldBatchPublish) {
            // Own the batch buffer here so flush can retry with reconnect without
            // silently dropping messages when channel() replaces a dead channel.
            $this->batchMessages[] = [
                $amqpEnvelope->getAMQPMessage(),
                $exchangeName,
                $publishRoutingKey ?? '',
            ];

            // Compare with >= rather than ==: a flush that throws keeps its messages
            // buffered, so an == threshold would be stepped over by the next publish and
            // auto-flush would never fire again, growing the buffer without bound.
            if (count($this->batchMessages) >= $batchSize) {
                $this->flush();
            }
        } else {
            $this->retryPublisher(function () use ($amqpEnvelope, $exchangeName, $publishRoutingKey): void {
                // A known broker alarm rejects publishes before they touch the channel. Do
                // not open and then discard another channel on every caller retry.
                $this->throwIfConnectionBlocked();

                try {
                    $channel = $this->channel();

                    if ($this->connectionConfig->transactionsEnabled) {
                        $channel->tx_select();
                    }

                    try {
                        $channel->basic_publish(
                            msg: $amqpEnvelope->getAMQPMessage(),
                            exchange: $exchangeName,
                            routing_key: $publishRoutingKey ?? '',
                        );

                        if ($this->connectionConfig->transactionsEnabled) {
                            $channel->tx_commit();
                        }

                        if ($this->connectionConfig->confirmEnabled) {
                            $this->waitForBatchConfirm($channel);
                        }
                    } catch (PublisherNack $e) {
                        if ($this->connectionConfig->transactionsEnabled) {
                            $this->rollbackTransaction($channel);
                        }

                        throw new TransportException($e->getMessage(), 0, $e);
                    } catch (AMQPExceptionInterface $e) {
                        if ($this->connectionConfig->transactionsEnabled) {
                            $this->rollbackTransaction($channel);
                        }

                        throw $e;
                    }
                } catch (TransportException $e) {
                    // Live confirm timeouts re-wait on the original channel. Discarding
                    // it here would make retryPublisher republish a message the broker
                    // may already have accepted.
                    if (! $e->getPrevious() instanceof AMQPTimeoutException) {
                        $this->discardChannel();
                    }

                    throw $e;
                } catch (Throwable $e) {
                    // A failed attempt can leave the channel in a selected/dirty transaction
                    // (or confirm) state. Drop it so the next publish cannot reuse it.
                    $this->discardChannel();

                    throw $e;
                }
            })->run();
        }
    }

    /** @throws TransportException */
    public function flush(): void
    {
        if ($this->batchMessages === []) {
            return;
        }

        // Do not reconnect proactively here: the connection is shared with the receiver,
        // and reconnecting would invalidate acknowledgements for in-flight deliveries.
        // A failed attempt discards its dirty channel below; channel() will reconnect
        // lazily if the underlying connection is actually dead.
        $this->retry(function (): void {
            // Keep a known-blocked connection from consuming one channel ID per flush.
            // The batch remains owned here and can be retried after the alarm clears.
            $this->throwIfConnectionBlocked();

            try {
                if ($this->pendingBatchConfirmChannel !== null) {
                    $this->waitForBatchConfirm($this->pendingBatchConfirmChannel);
                    $this->pendingBatchConfirmChannel = null;

                    return;
                }

                $channel = $this->channel();

                foreach ($this->batchMessages as [$message, $exchangeName, $routingKey]) {
                    $channel->batch_basic_publish(
                        message: $message,
                        exchange: $exchangeName,
                        routing_key: $routingKey,
                    );
                }

                if ($this->connectionConfig->transactionsEnabled) {
                    $channel->tx_select();
                }

                try {
                    $channel->publish_batch();

                    if ($this->connectionConfig->transactionsEnabled) {
                        $channel->tx_commit();
                    }

                    if ($this->connectionConfig->confirmEnabled) {
                        $this->pendingBatchConfirmChannel = $channel;
                        $this->waitForBatchConfirm($channel);
                        $this->pendingBatchConfirmChannel = null;
                    }
                } catch (PublisherNack $e) {
                    if ($this->connectionConfig->transactionsEnabled) {
                        $this->rollbackTransaction($channel);
                    }

                    // A NACK means the broker rejected at least one confirm. php-amqplib
                    // already dropped that delivery tag, so re-waiting this channel can
                    // return immediately and look like success. Drop the confirm channel
                    // and keep the batch so the caller can retry without auto-replay.
                    $this->discardChannel();

                    throw new TransportException($e->getMessage(), 0, $e);
                } catch (AMQPExceptionInterface $e) {
                    if ($this->connectionConfig->transactionsEnabled) {
                        $this->rollbackTransaction($channel);
                    }

                    throw $e;
                }
            } catch (TransportException $e) {
                // Exhausting live confirm timeouts leaves the original channel and batch
                // pending so a later flush can re-wait without republishing either message.
                if ($this->pendingBatchConfirmChannel === null) {
                    $this->discardChannel();
                }

                throw $e;
            } catch (Throwable $e) {
                // A failed publish_batch can leave messages in php-amqplib's per-channel
                // batch buffer. Drop the cached channel so a later flush() cannot append
                // onto that leftover buffer and duplicate when the broker recovers.
                $this->discardChannel();

                throw $e;
            }
        })->run();

        $this->batchMessages = [];
    }

    public function countMessagesInQueues(): int
    {
        return array_sum(array_map(fn ($queueName) => $this->countMessagesInQueue($queueName), $this->getQueueNames()));
    }

    /** @return array<string> */
    public function getQueueNames(): array
    {
        return $this->connectionConfig->getQueueNames();
    }

    /**
     * @param positive-int|0 $waitTime
     *
     * @throws TransportException
     */
    public function retryWithReconnect(
        Closure $run,
        int|null $retries = null,
        int|null $waitTime = null,
        bool|null $jitter = null,
    ): Retry {
        return $this->retryFactory->retry(
            $run,
            $this->resolveRetries($retries),
            $waitTime,
            $jitter,
        )
            ->beforeRetry(function (): void {
                $this->reconnect();
            });
    }

    /**
     * @param positive-int|0 $waitTime
     *
     * @throws TransportException
     */
    public function retry(
        Closure $run,
        int|null $retries = null,
        int|null $waitTime = null,
        bool|null $jitter = null,
    ): Retry {
        return $this->retryFactory->retry(
            $run,
            $this->resolveRetries($retries),
            $waitTime,
            $jitter,
        );
    }

    /**
     * Retries a publisher operation without replacing a healthy shared connection.
     *
     * The failed publisher channel is discarded by the operation before a retry. A
     * reconnect is necessary only when the entire AMQP connection is dead; reconnecting
     * for a channel-local failure would invalidate live consumer delivery tags.
     *
     * @param positive-int|0 $waitTime
     *
     * @throws TransportException
     */
    private function retryPublisher(
        Closure $run,
        int|null $retries = null,
        int|null $waitTime = null,
        bool|null $jitter = null,
    ): Retry {
        return $this->retryFactory->retry(
            $run,
            $this->resolveRetries($retries),
            $waitTime,
            $jitter,
        )
            ->beforeRetry(function (): void {
                if (! $this->isConnected()) {
                    $this->reconnect();
                }
            });
    }

    /** Uses the configured retry budget unless the caller passed an explicit count. */
    private function resolveRetries(int|null $retries): int|null
    {
        if ($retries !== null) {
            return $retries;
        }

        if (! $this->connectionConfig->retriesEnabled) {
            return 0;
        }

        return null;
    }

    /** Drops a failed publisher channel without disturbing a consumer on this connection. */
    private function discardChannel(): void
    {
        $channel       = $this->channel;
        $this->channel = null;

        if ($this->pendingBatchConfirmChannel === $channel) {
            $this->pendingBatchConfirmChannel = null;
        }

        if ($channel === null || $channel->closeIfDisconnected()) {
            return;
        }

        // Closing while a broker alarm is active can block waiting for channel.close-ok.
        // Keep the publisher-only channel aside and close it before opening its replacement
        // once the connection is readable again. This bounds channel usage across repeated
        // alarm episodes without invalidating deliveries on the separate consumer channel.
        $this->retiredPublisherChannels[] = $channel;
    }

    /**
     * Forgets every channel before the underlying connection is replaced or found dead.
     * Delivery tags are scoped to the old consumer channel, so its local state goes too.
     */
    private function forgetChannels(): void
    {
        $channels = [
            $this->channel,
            $this->consumerChannel,
            ...$this->retiredPublisherChannels,
        ];

        $this->channel                    = null;
        $this->consumerChannel            = null;
        $this->pendingBatchConfirmChannel = null;
        $this->retiredPublisherChannels   = [];
        $this->invalidateConsumers();

        foreach ($channels as $channel) {
            $channel?->closeIfDisconnected();
        }
    }

    /** @throws AMQPExceptionInterface */
    private function closeRetiredPublisherChannels(): void
    {
        if ($this->retiredPublisherChannels === []) {
            return;
        }

        if (! $this->isConnected()) {
            foreach ($this->retiredPublisherChannels as $channel) {
                $channel->closeIfDisconnected();
            }

            $this->retiredPublisherChannels = [];

            return;
        }

        $this->throwIfConnectionBlocked();

        while ($this->retiredPublisherChannels !== []) {
            $channel = array_shift($this->retiredPublisherChannels);

            try {
                $channel->close();
            } catch (AMQPExceptionInterface $e) {
                if (! $channel->closeIfDisconnected()) {
                    array_unshift($this->retiredPublisherChannels, $channel);
                }

                throw $e;
            }
        }
    }

    private function rollbackTransaction(AMQPChannel $channel): void
    {
        try {
            $channel->tx_rollback();
        } catch (AMQPExceptionInterface $rollbackException) {
            // The publish failure drives retry behavior and is the useful root cause. Keep
            // it as the thrown exception even if best-effort rollback fails on the same channel.
            $this->logger?->warning('AMQP transaction rollback failed: {message}', [
                'message' => $rollbackException->getMessage(),
                'exception' => $rollbackException,
            ]);
        }
    }

    /** @throws TransportException */
    private function waitForBatchConfirm(AMQPChannel $channel): void
    {
        // Do not retry timeouts here: flush()/publish() already own retry. Nested
        // default retries would wait confirmTimeout many extra times per call.
        $this->retry(
            function () use ($channel): void {
                $channel->wait_for_pending_acks(timeout: $this->connectionConfig->confirmTimeout);
            },
            retries: 0,
        )
            ->catch(AMQPTimeoutException::class)
            ->run();
    }

    /** @throws AMQPExceptionInterface */
    private function throwIfConnectionBlocked(): void
    {
        $connection = $this->connection;

        if ($connection === null || ! $connection->isBlocked()) {
            return;
        }

        // connection.unblocked is asynchronous. A publisher that stops writing while an
        // alarm is active must still poll the socket so php-amqplib can dispatch that
        // frame and clear its cached blocked flag. Non-blocking mode drains only data
        // already available and preserves frames belonging to the consumer channel.
        $connection->wait(allowed_methods: null, non_blocking: true);

        if ($connection->isBlocked()) {
            throw new AMQPConnectionBlockedException();
        }
    }

    private function invalidateConsumers(): void
    {
        foreach ($this->consumers as $consumer) {
            $consumer->invalidate();
        }
    }

    private function getRoutingKeyForMessage(AmqpStamp|null $amqpStamp): string|null
    {
        return $amqpStamp?->getRoutingKey() ?? $this->connectionConfig->exchange->defaultPublishRoutingKey;
    }

    /** @throws InvalidArgumentException */
    private function countMessagesInQueue(string $queueName): int
    {
        return $this->declareQueue($queueName);
    }

    /**
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    private function setupExchangeAndQueues(): void
    {
        try {
            if ($this->connectionConfig->exchange->name) {
                $this->setupExchange();
            }

            foreach ($this->connectionConfig->queues as $queueConfig) {
                $this->setupQueue($queueConfig);
            }

            $this->autoSetup = false;
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws AMQPTimeoutException
     * @throws TransportException
     */
    private function setupExchange(): void
    {
        $this->channel()->exchange_declare(
            exchange: $this->connectionConfig->exchange->name,
            type: $this->connectionConfig->exchange->type,
            passive: $this->connectionConfig->exchange->passive,
            durable: $this->connectionConfig->exchange->durable,
            auto_delete: $this->connectionConfig->exchange->autoDelete,
            nowait: false,
            arguments: new AMQPTable($this->connectionConfig->exchange->arguments),
        );
    }

    /** @throws InvalidArgumentException */
    private function setupQueue(QueueConfig $queueConfig): void
    {
        $this->declareQueue($queueConfig->name);

        if (! $this->connectionConfig->exchange->name) {
            return;
        }

        $bindings = $queueConfig->bindings
            ? $queueConfig->bindings
            : [null];

        foreach ($bindings as $bindingConfig) {
            $this->channel()->queue_bind(
                queue: $queueConfig->name,
                exchange: $this->connectionConfig->exchange->name,
                routing_key: $bindingConfig?->routingKey ?? '',
                nowait: false,
                arguments: new AMQPTable($bindingConfig?->arguments ?? []),
            );
        }
    }

    /** @throws InvalidArgumentException */
    private function declareQueue(string $queueName): int
    {
        $queueConfig = $this->connectionConfig->getQueueConfig($queueName);

        [$_queueName, $messageCount] = $this->channel()->queue_declare(
            queue: $queueName,
            passive: $queueConfig->passive,
            durable: $queueConfig->durable,
            exclusive: $queueConfig->exclusive,
            auto_delete: $queueConfig->autoDelete,
            nowait: false,
            arguments: new AMQPTable($queueConfig->arguments),
        ) ?? [$queueName, 0];

        return (int) $messageCount;
    }

    /** @throws TransportException */
    private function setupDelayExchangeAndQueue(
        int $delay,
        string|null $routingKey,
        bool $isRetryAttempt,
    ): void {
        $this->retryWithReconnect(function () use ($delay, $routingKey, $isRetryAttempt): void {
            if ($this->autoSetupDelay) {
                $this->setupDelayExchange();
            }

            $this->setupDelayQueue($delay, $routingKey, $isRetryAttempt);
        })->run();
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws TransportException
     */
    private function setupDelayExchange(): void
    {
        $this->channel()->exchange_declare(
            exchange: $this->connectionConfig->delay->exchange->name,
            type: $this->connectionConfig->delay->exchange->type,
            passive: $this->connectionConfig->delay->exchange->passive,
            durable: $this->connectionConfig->delay->exchange->durable,
            auto_delete: $this->connectionConfig->delay->exchange->autoDelete,
            nowait: false,
            arguments: new AMQPTable($this->connectionConfig->delay->exchange->arguments),
        );

        $this->autoSetupDelay = false;
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws TransportException
     */
    private function setupDelayQueue(int $delay, string|null $routingKey, bool $isRetryAttempt): void
    {
        $delayQueueName = $this->connectionConfig
            ->getDelayQueueName($delay, $routingKey, $isRetryAttempt);

        $this->channel()->queue_declare(
            queue: $delayQueueName,
            durable: $this->connectionConfig->delay->durable,
            nowait: false,
            arguments: new AMQPTable([
                'x-message-ttl' => $delay,
                'x-expires' => $delay + 10000,
                'x-dead-letter-exchange' => $isRetryAttempt ? '' : $this->connectionConfig->exchange->name,
                'x-dead-letter-routing-key' => $routingKey ?? '',
            ]),
        );

        $this->channel()->queue_bind(
            queue: $delayQueueName,
            exchange: $this->connectionConfig->delay->exchange->name,
            routing_key: $delayQueueName,
            nowait: false,
        );
    }

    /** @throws AMQPExceptionInterface */
    private function connection(): AMQPStreamConnection
    {
        if ($this->connection === null) {
            $this->connection = $this->amqpConnectionFactory->create($this->connectionConfig);
        }

        return $this->connection;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $headers
     */
    private function createAMQPEnvelope(string $body, array $attributes, array $headers): AmqpEnvelope
    {
        /** @var array<string, mixed> $attributeHeaders */
        $attributeHeaders = $attributes['headers'] ?? [];

        $headers = array_merge($attributeHeaders, $headers);

        unset($attributes['headers']);

        return new AmqpEnvelope(
            new AMQPMessage(
                $body,
                [
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'application_headers' => new AMQPTable($headers),
                    ...$attributes,
                ],
            ),
        );
    }
}

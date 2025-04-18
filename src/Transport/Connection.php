<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Closure;
use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\Messenger\Exception\TransportException;

use function array_map;
use function array_merge;
use function array_sum;
use function assert;

class Connection
{
    private AMQPStreamConnection|null $connection = null;

    private AMQPChannel|null $channel = null;

    private AmqpConsumer|null $consumer = null;

    private int $batchCount = 0;

    private bool $autoSetup;

    private bool $autoSetupDelay;

    public function __construct(
        private RetryFactory $retryFactory,
        private AmqpConnectionFactory $amqpConnectionFactory,
        private ConnectionConfig $connectionConfig,
    ) {
        $this->autoSetup      = $connectionConfig->autoSetup;
        $this->autoSetupDelay = $connectionConfig->autoSetup;
    }

    public function __destruct()
    {
        $this->connection = null;
        $this->channel    = null;
        $this->consumer   = null;
    }

    public function getConfig(): ConnectionConfig
    {
        return $this->connectionConfig;
    }

    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    /** @throws AMQPExceptionInterface */
    public function reconnect(): void
    {
        $this->connection?->reconnect();
        $this->channel  = null;
        $this->consumer = null;
    }

    /**
     * @throws TransportException
     * @throws InvalidArgumentException
     */
    public function setup(): void
    {
        $this->setupExchangeAndQueues();
        $this->setupDelayExchange();
    }

    /** @throws TransportException */
    public function channel(): AMQPChannel
    {
        if ($this->channel === null) {
            $channel = $this->withRetry(function (): AMQPChannel {
                $channel = $this->connection()->channel();

                if ($this->connectionConfig->confirmEnabled) {
                    $channel->confirm_select();
                }

                return $channel;
            })->run();
            assert($channel instanceof AMQPChannel);

            $this->channel = $channel;
        }

        return $this->channel;
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
        if ($this->autoSetup) {
            $this->setupExchangeAndQueues();
        }

        /** @var array<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $this->withRetry(function () use ($queueName): array {
            return ($this->consumer ??= new AmqpConsumer($this, $this->connectionConfig))->get($queueName);
        })->run();

        return $amqpEnvelopes;
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
        if ($this->autoSetup) {
            $this->setupExchangeAndQueues();
        }

        /** @var array<string, mixed> $attributeHeaders */
        $attributeHeaders = $amqpStamp?->getAttributes()['headers'] ?? [];

        $headers = array_merge($attributeHeaders, $headers);

        $amqpEnvelope = $this->createAMQPEnvelope($body, $headers);

        $isDelayed      = $delayInMs > 0;
        $isRetryAttempt = $amqpStamp && $amqpStamp->isRetryAttempt();
        $routingKey     = $this->getRoutingKeyForMessage($amqpStamp);

        if ($isDelayed) {
            $publishRoutingKey = $this->connectionConfig->delay->getQueueName(
                $delayInMs,
                $routingKey,
                $isRetryAttempt,
            );

            $this->setupDelayExchangeAndQueue(
                $delayInMs,
                $routingKey,
                $isRetryAttempt,
            );
        } else {
            $publishRoutingKey = $routingKey;
        }

        $exchangeName = $isDelayed
            ? $this->connectionConfig->delay->exchange->name
            : $this->connectionConfig->exchange->name;

        if ($batchSize > 1) {
            $this->channel()->batch_basic_publish(
                message: $amqpEnvelope->getAMQPMessage(),
                exchange: $exchangeName,
                routing_key: $publishRoutingKey ?? '',
            );

            $this->batchCount++;

            if ($this->batchCount === $batchSize) {
                $this->flush();
            }
        } else {
            $this->withRetry(function () use ($amqpEnvelope, $exchangeName, $publishRoutingKey): void {
                $this->channel()->basic_publish(
                    msg: $amqpEnvelope->getAMQPMessage(),
                    exchange: $exchangeName,
                    routing_key: $publishRoutingKey ?? '',
                );

                if (! $this->connectionConfig->confirmEnabled) {
                    return;
                }

                $this->channel()->wait_for_pending_acks(timeout: $this->connectionConfig->confirmTimeout);
            })->run();
        }
    }

    /** @throws TransportException */
    public function flush(): void
    {
        $this->withRetry(function (): void {
            $this->channel()->publish_batch();

            if (! $this->connectionConfig->confirmEnabled) {
                return;
            }

            $this->channel()->wait_for_pending_acks(timeout: $this->connectionConfig->confirmTimeout);
        })->run();

        $this->batchCount = 0;
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
    public function withRetry(
        Closure $run,
        int|null $retries = null,
        int|null $waitTime = null,
        bool|null $jitter = null,
    ): Retry {
        return $this->retryFactory->retry(
            $run,
            $retries,
            $waitTime,
            $jitter,
        )
            ->beforeRetry(function (): void {
                $this->reconnect();
            });
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
                $this->channel()->exchange_declare(
                    exchange: $this->connectionConfig->exchange->name,
                    type: $this->connectionConfig->exchange->type,
                    passive: $this->connectionConfig->exchange->passive,
                    durable: $this->connectionConfig->exchange->durable,
                    auto_delete: $this->connectionConfig->exchange->autoDelete,
                    nowait: true,
                    arguments: new AMQPTable($this->connectionConfig->exchange->arguments),
                );
            }

            foreach ($this->connectionConfig->queues as $queueName => $queueConfig) {
                $this->declareQueue($queueName);

                if (! $this->connectionConfig->exchange->name) {
                    continue;
                }

                $bindings = $queueConfig->bindings
                    ? $queueConfig->bindings
                    : [null];

                foreach ($bindings as $bindingConfig) {
                    $this->channel()->queue_bind(
                        queue: $queueName,
                        exchange: $this->connectionConfig->exchange->name,
                        routing_key: $bindingConfig?->routingKey ?? '',
                        nowait: true,
                        arguments: new AMQPTable($bindingConfig?->arguments ?? []),
                    );
                }
            }

            $this->autoSetup = false;
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
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
            nowait: true,
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
        if ($this->autoSetupDelay) {
            $this->setupDelayExchange();
        }

        $this->setupDelayQueue($delay, $routingKey, $isRetryAttempt);
    }

    /** @throws TransportException */
    private function setupDelayExchange(): void
    {
        try {
            $this->channel()->exchange_declare(
                exchange: $this->connectionConfig->delay->exchange->name,
                type: $this->connectionConfig->delay->exchange->type,
                passive: $this->connectionConfig->delay->exchange->passive,
                durable: $this->connectionConfig->delay->exchange->durable,
                auto_delete: $this->connectionConfig->delay->exchange->autoDelete,
                nowait: true,
                arguments: new AMQPTable($this->connectionConfig->delay->exchange->arguments),
            );

            $this->autoSetupDelay = false;
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /** @throws TransportException */
    private function setupDelayQueue(int $delay, string|null $routingKey, bool $isRetryAttempt): void
    {
        try {
            $delayQueueName = $this->connectionConfig->delay
                ->getQueueName($delay, $routingKey, $isRetryAttempt);

            $this->channel()->queue_declare(
                queue: $delayQueueName,
                nowait: true,
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
                nowait: true,
            );
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /** @throws AMQPExceptionInterface */
    private function connection(): AMQPStreamConnection
    {
        if ($this->connection === null) {
            $this->connection = $this->amqpConnectionFactory->create($this->connectionConfig);
        }

        return $this->connection;
    }

    /** @param array<string, mixed> $headers */
    private function createAMQPEnvelope(string $body, array $headers): AmqpEnvelope
    {
        return new AmqpEnvelope(
            new AMQPMessage(
                $body,
                [
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'application_headers' => new AMQPTable(['protocol' => 3, ...$headers]),
                ],
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

use function array_map;
use function array_sum;
use function assert;

class Connection
{
    private AMQPStreamConnection|null $connection = null;

    private AMQPChannel|null $channel = null;

    private AMQPConsumer|null $consumer = null;

    private int $batchCount = 0;

    private bool $autoSetup;

    private bool $autoSetupDelay;

    public function __construct(
        private RetryFactory $retryFactory,
        private AMQPConnectionFactory $amqpConnectionFactory,
        private ConnectionConfig $connectionConfig,
    ) {
        $this->autoSetup      = $connectionConfig->autoSetup;
        $this->autoSetupDelay = $connectionConfig->autoSetup;
    }

    public function __destruct()
    {
        try {
            $this->disconnect();
        } catch (AMQPExceptionInterface) {
        }
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /** @throws AMQPExceptionInterface */
    public function connect(): void
    {
        $this->connection = $this->amqpConnectionFactory->create($this->connectionConfig);
    }

    public function disconnect(): void
    {
        $this->channel?->close();
        $this->connection?->close();

        $this->channel    = null;
        $this->connection = null;
        $this->consumer   = null;
    }

    /** @throws AMQPExceptionInterface */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws InvalidArgumentException
     */
    public function setup(): void
    {
        $this->setupExchangeAndQueues();
        $this->setupDelayExchange();
    }

    /** @throws AMQPExceptionInterface */
    public function channel(): AMQPChannel
    {
        if ($this->channel === null) {
            $this->channel = $this->connection()->channel();
            $this->channel->confirm_select();
        }

        return $this->channel;
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws InvalidArgumentException
     */
    public function get(string $queueName): AMQPEnvelope|null
    {
        if ($this->autoSetup) {
            $this->setupExchangeAndQueues();
        }

        return ($this->consumer ??= new AMQPConsumer($this, $this->connectionConfig))->get($queueName);
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @throws AMQPExceptionInterface
     * @throws InvalidArgumentException
     */
    public function publish(
        string $body,
        int $delayInMs = 0,
        int $batchSize = 1,
        AMQPStamp|null $amqpStamp = null,
    ): void {
        if ($this->autoSetup) {
            $this->setupExchangeAndQueues();
        }

        $amqpEnvelope = $this->createAMQPEnvelope($body);

        $channel = $this->channel();

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
            $channel->batch_basic_publish(
                message: $amqpEnvelope->getAMQPMessage(),
                exchange: $exchangeName,
                routing_key: $publishRoutingKey ?? '',
            );

            $this->batchCount++;

            if ($this->batchCount === $batchSize) {
                $this->flush();
            }
        } else {
            $channel->basic_publish(
                msg: $amqpEnvelope->getAMQPMessage(),
                exchange: $exchangeName,
                routing_key: $publishRoutingKey ?? '',
            );

            $channel->wait_for_pending_acks(timeout: 3);
        }
    }

    /** @throws AMQPExceptionInterface */
    public function flush(): void
    {
        $this->channel()->publish_batch();

        $this->channel()->wait_for_pending_acks(3);

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

    private function getRoutingKeyForMessage(AMQPStamp|null $amqpStamp): string|null
    {
        return $amqpStamp?->getRoutingKey() ?? $this->connectionConfig->exchange->defaultPublishRoutingKey;
    }

    /** @throws InvalidArgumentException */
    private function countMessagesInQueue(string $queueName): int
    {
        return $this->declareQueue($queueName);
    }

    /**
     * @throws AMQPExceptionInterface
     * @throws InvalidArgumentException
     */
    private function setupExchangeAndQueues(): void
    {
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

        foreach ($this->connectionConfig->queues as $queueName => $queueOptions) {
            $this->declareQueue($queueName);

            if (! $this->connectionConfig->exchange->name) {
                continue;
            }

            $bindingKeys = $queueOptions->bindingKeys
                ? $queueOptions->bindingKeys
                : [null];

            foreach ($bindingKeys as $bindingKey) {
                $this->channel()->queue_bind(
                    queue: $queueName,
                    exchange: $this->connectionConfig->exchange->name,
                    routing_key: $bindingKey ?? '',
                    nowait: true,
                    arguments: new AMQPTable($queueOptions->bindingArguments),
                );
            }
        }

        $this->autoSetup = false;
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

    /** @throws AMQPExceptionInterface */
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

    /** @throws AMQPExceptionInterface */
    private function setupDelayExchange(): void
    {
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
    }

    /** @throws AMQPExceptionInterface */
    private function setupDelayQueue(int $delay, string|null $routingKey, bool $isRetryAttempt): void
    {
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
    }

    /** @throws AMQPExceptionInterface */
    private function connection(): AMQPStreamConnection
    {
        if (! $this->isConnected()) {
            $this->connect();
        }

        assert($this->connection instanceof AMQPStreamConnection);

        return $this->connection;
    }

    private function createAMQPEnvelope(string $body): AMQPEnvelope
    {
        return new AMQPEnvelope(
            new AMQPMessage(
                $body,
                [
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'application_headers' => new AMQPTable(['protocol' => 3]),
                ],
            ),
        );
    }
}

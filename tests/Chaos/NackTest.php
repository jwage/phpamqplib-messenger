<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Transport\PublisherNack;
use Symfony\Component\Messenger\Exception\TransportException;

class NackTest extends ChaosTestCase
{
    public function testDirectPublishNackFromOverflowIsNotRetried(): void
    {
        $name       = $this->harness->topologyName('nack');
        $connection = $this->harness->connect($this->harness->topology($name, [
            'arguments' => [
                'x-max-length' => 1,
                'x-overflow' => 'reject-publish',
            ],
        ]));

        $connection->publish(body: 'kept');
        self::assertSame(1, $connection->countMessagesInQueues());

        $elapsed = (float) $this->harness->withRetryDefaults(3, 500, function () use ($connection): float {
            return $this->harness->milliseconds(static function () use ($connection): void {
                try {
                    $connection->publish(body: 'rejected');
                    self::fail('Expected the overflowing publish to fail with a NACK');
                } catch (TransportException $exception) {
                    self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                }
            });
        });

        self::assertLessThan(400, $elapsed);
        self::assertSame(0, $this->harness->logger()->countRetryLogs());
        self::assertSame(1, $connection->countMessagesInQueues());
    }

    public function testBatchFlushNackFromOverflowIsNotRetriedOrReplayed(): void
    {
        $name       = $this->harness->topologyName('batch_nack');
        $connection = $this->harness->connect($this->harness->topology($name, [
            'arguments' => [
                'x-max-length' => 1,
                'x-overflow' => 'reject-publish',
            ],
        ]));

        $connection->publish(body: 'kept');
        self::assertSame(1, $connection->countMessagesInQueues());

        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $elapsed = (float) $this->harness->withRetryDefaults(3, 500, function () use ($connection): float {
            return $this->harness->milliseconds(static function () use ($connection): void {
                try {
                    $connection->flush();
                    self::fail('Expected the overflowing batch flush to fail with a NACK');
                } catch (TransportException $exception) {
                    self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                }
            });
        });

        self::assertLessThan(400, $elapsed);
        self::assertSame(0, $this->harness->logger()->countRetryLogs());
        self::assertSame(2, $this->harness->pendingBatchSize($connection));
        self::assertSame(1, $connection->countMessagesInQueues());
    }

    public function testConsumerAckSurvivesPublisherNack(): void
    {
        $name         = $this->harness->topologyName('nack_ack');
        $overflowName = $name . '_ov';
        $connection   = $this->harness->connect($this->harness->topology($name, extra: [
            'queues' => [
                $name => ['wait_timeout' => 2],
                $overflowName => [
                    'arguments' => [
                        'x-max-length' => 1,
                        'x-overflow' => 'reject-publish',
                    ],
                ],
            ],
        ]));

        $connection->publish(body: 'to-ack');
        $envelope        = $this->harness->consumeOne($connection, $name);
        $publisherBefore = $connection->channel();
        $consumerBefore  = $connection->consumerChannel();
        self::assertNotSame($publisherBefore, $consumerBefore);

        $elapsed = (float) $this->harness->withRetryDefaults(3, 500, function () use ($connection): float {
            return $this->harness->milliseconds(static function () use ($connection): void {
                try {
                    $connection->publish(body: 'rejected');
                    self::fail('Expected the overflowing publish to fail with a NACK');
                } catch (TransportException $exception) {
                    self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                }
            });
        });

        self::assertLessThan(400, $elapsed);
        self::assertSame(
            $consumerBefore,
            $connection->consumerChannel(),
            'A publisher NACK must discard only the publisher channel, not the consumer channel holding the delivery tag',
        );

        $envelope->ack();
    }
}

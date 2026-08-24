<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Throwable;

class BrokerRestartTest extends ChaosTestCase
{
    public function testRetainedBatchFlushesAfterBrokerRestart(): void
    {
        $name       = $this->harness->topologyName('restart_before');
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->publish(body: 'one', batchSize: 3);
        $connection->publish(body: 'two', batchSize: 3);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $this->harness->info('Restarting broker before flush');
        $this->harness->broker('restart');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->flush();
        });

        self::assertSame(0, $this->harness->pendingBatchSize($connection));
        self::assertSame(2, $connection->countMessagesInQueues());
    }

    public function testInFlightFlushSurvivesBrokerRestart(): void
    {
        $name       = $this->harness->topologyName('restart_during');
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->publish(body: 'one', batchSize: 3);
        $connection->publish(body: 'two', batchSize: 3);

        $this->harness->info('Restarting broker during flush');
        $this->harness->brokerLater(0.2, 'restart');

        $this->harness->withRetryDefaults(8, 250, function () use ($connection): void {
            try {
                $connection->flush();
            } catch (Throwable $exception) {
                $this->harness->info('Flush threw during restart: ' . $exception->getMessage());
            }
        });

        $this->harness->waitUntilReady();

        if ($this->harness->pendingBatchSize($connection) > 0) {
            $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
                $connection->flush();
            });
        }

        $count = $connection->countMessagesInQueues();
        self::assertGreaterThanOrEqual(2, $count);
        self::assertLessThanOrEqual(4, $count);
    }

    public function testDirectPublishRecoversAfterBrokerRestart(): void
    {
        $name       = $this->harness->topologyName('direct_restart');
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->publish(body: 'before-restart');
        self::assertSame(1, $connection->countMessagesInQueues());

        $this->harness->info('Restarting broker after a successful direct publish');
        $this->harness->broker('restart');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'after-restart');
        });

        self::assertGreaterThanOrEqual(2, $connection->countMessagesInQueues());
    }

    public function testConsumerAcksAfterBrokerRestart(): void
    {
        $name       = $this->harness->topologyName('consumer_restart');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2]));

        $connection->publish(body: 'survive-restart');
        self::assertSame(1, $connection->countMessagesInQueues());

        $this->harness->info('Restarting broker with a persistent unconsumed message');
        $this->harness->broker('restart');
        $this->harness->waitUntilReady();

        $envelope = $this->harness->withRetryDefaults(5, 200, function () use ($connection, $name): AmqpEnvelope {
            return $this->harness->consumeOne($connection, $name);
        });

        self::assertInstanceOf(AmqpEnvelope::class, $envelope);
        $envelope->ack();

        self::assertSame(0, $connection->countMessagesInQueues());
    }

    public function testDelayTopologyIsRecreatedAfterBrokerRestart(): void
    {
        $name       = $this->harness->topologyName('delay_restart');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'delay' => [
                'enabled' => true,
                'auto_setup' => true,
                'exchange' => ['name' => $name . '_delays'],
                'queue_name_pattern' => $name . '_%delay%',
            ],
        ]));

        $connection->setup();

        $this->harness->info('Restarting broker before a delayed publish');
        $this->harness->broker('restart');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'delayed', delayInMs: 300);
        });

        self::assertGreaterThanOrEqual(1, $this->harness->waitForMessageCount($connection, 1, 10));
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

class HeartbeatStallTest extends ChaosTestCase
{
    public function testPublishRecoversAfterHeartbeatStall(): void
    {
        $name       = $this->harness->topologyName('heartbeat_stall');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'heartbeat' => 1,
            'read_timeout' => 3,
            'write_timeout' => 3,
            'rpc_timeout' => 3,
        ]));

        $connection->publish(body: 'before-stall');
        self::assertSame(1, $connection->countMessagesInQueues());

        $this->harness->info('Pausing broker so heartbeat frames cannot be answered');
        $this->harness->broker('pause');

        $this->harness->withRetryDefaults(0, 0, function () use ($connection): void {
            try {
                $connection->publish(body: 'during-stall');
                self::fail('Expected publish to fail while the broker is paused');
            } catch (AssertionFailedError $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $this->harness->info('Publish failed during stall: ' . $exception->getMessage());
            }
        });

        $this->harness->broker('unpause');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'after-stall');
        });

        self::assertGreaterThanOrEqual(2, $connection->countMessagesInQueues());
    }

    public function testConsumeWaitRecoversAfterHeartbeatStall(): void
    {
        $name       = $this->harness->topologyName('consume_wait_stall');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 10], [
            'heartbeat' => 1,
            'read_timeout' => 3,
            'write_timeout' => 3,
            'rpc_timeout' => 3,
        ]));

        $connection->setup();
        self::assertSame(0, $connection->countMessagesInQueues());

        $this->harness->info('Pausing broker while consume() is blocked in wait()');
        $this->harness->brokerLater(0.3, 'pause');

        try {
            foreach ($connection->consume($name) as $unexpected) {
                self::fail(
                    'Expected no delivery while waiting on an empty queue during a heartbeat stall, got: '
                    . $unexpected->getBody(),
                );
            }
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->harness->info('consume() threw during stall: ' . $exception->getMessage());
        }

        self::assertNull(
            $this->harness->wrappedAmqpConnection($connection),
            'consume() must Connection::close() when wait() hits a paused-broker I/O error, not leave a stale AMQP connection',
        );

        $this->harness->broker('unpause');
        $this->harness->waitUntilReady();

        $this->harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'after-consume-stall');
        });

        $envelope = $this->harness->withRetryDefaults(5, 200, function () use ($connection, $name): AmqpEnvelope {
            return $this->harness->consumeOnce($connection, $name);
        });

        $envelope->ack();
        self::assertSame(0, $connection->countMessagesInQueues());
    }
}

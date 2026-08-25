<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use Symfony\Component\Messenger\Exception\TransportException;

class ConfirmTimeoutTest extends ChaosTestCase
{
    public function testBatchConfirmTimeoutRewaitsWithoutRepublishing(): void
    {
        $name       = $this->harness->topologyName('confirm_timeout');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'confirm_timeout' => 1,
            'read_timeout' => 3,
            'write_timeout' => 3,
            'heartbeat' => 0,
        ]));

        $connection->publish(body: 'one', batchSize: 3);
        $connection->publish(body: 'two', batchSize: 3);
        self::assertSame(2, $this->harness->pendingBatchSize($connection));

        $this->harness->info('Pausing broker after the batch is buffered, before confirm wait');
        $this->harness->broker('pause');

        try {
            try {
                $connection->flush();
                self::fail('Expected flush to time out while the broker is paused');
            } catch (TransportException $exception) {
                $this->harness->info('Flush timed out: ' . $exception->getMessage());
            }

            self::assertSame(2, $this->harness->pendingBatchSize($connection));
            self::assertNotNull($this->harness->pendingConfirmChannel($connection));
        } finally {
            $this->harness->broker('unpause');
        }

        $connection->publish(body: 'three', batchSize: 1);

        self::assertSame(0, $this->harness->pendingBatchSize($connection));
        self::assertSame(3, $connection->countMessagesInQueues());
    }

    public function testDirectConfirmTimeoutKeepsChannelAndDoesNotRepublish(): void
    {
        $name       = $this->harness->topologyName('direct_confirm_timeout');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'confirm_timeout' => 1,
            'read_timeout' => 10,
            'write_timeout' => 10,
            'rpc_timeout' => 10,
            'heartbeat' => 0,
        ]));

        self::assertSame(1.0, $connection->getConfig()->confirmTimeout);

        // Finish topology RPC while the broker is live. Pausing first would time out
        // exchange_declare at rpc_timeout instead of wait_for_pending_acks at confirm_timeout.
        $connection->setup();
        $channelBefore = $connection->channel();

        // Pause after the channel is live so the publish write can buffer, then confirm
        // wait hits confirm_timeout instead of a nested I/O retry budget.
        $this->harness->info('Pausing broker after the publisher channel is open, before confirm wait');
        $this->harness->broker('pause');

        $retriesBefore = $this->harness->logger()->countRetryLogs();

        try {
            $elapsed = (float) $this->harness->withRetryDefaults(0, 0, function () use ($connection): float {
                return $this->harness->milliseconds(function () use ($connection): void {
                    try {
                        $connection->publish(body: 'direct-timeout');
                        self::fail('Expected direct publish to time out while the broker is paused');
                    } catch (TransportException $exception) {
                        self::assertInstanceOf(AMQPTimeoutException::class, $exception->getPrevious());
                        self::assertStringContainsString('after 1 sec', $exception->getMessage());
                        $this->harness->info('Direct publish timed out: ' . $exception->getMessage());
                    }
                });
            });

            self::assertLessThan(2500, $elapsed);
            self::assertSame($retriesBefore, $this->harness->logger()->countRetryLogs());
            self::assertSame($channelBefore, $this->harness->publisherChannel($connection));
        } finally {
            $this->harness->broker('unpause');
        }

        self::assertLessThanOrEqual(1, $connection->countMessagesInQueues());
    }
}

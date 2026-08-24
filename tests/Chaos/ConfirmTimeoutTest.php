<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

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
            $connection->flush();
            self::fail('Expected flush to time out while the broker is paused');
        } catch (TransportException $exception) {
            $this->harness->info('Flush timed out: ' . $exception->getMessage());
        }

        self::assertSame(2, $this->harness->pendingBatchSize($connection));
        self::assertNotNull($this->harness->pendingConfirmChannel($connection));

        $this->harness->broker('unpause');
        $connection->publish(body: 'three', batchSize: 1);

        self::assertSame(0, $this->harness->pendingBatchSize($connection));
        self::assertSame(3, $connection->countMessagesInQueues());
    }

    public function testDirectConfirmTimeoutKeepsChannelAndDoesNotRepublish(): void
    {
        $name       = $this->harness->topologyName('direct_confirm_timeout');
        $connection = $this->harness->connect($this->harness->topology($name, extra: [
            'confirm_timeout' => 1,
            'read_timeout' => 3,
            'write_timeout' => 3,
            'heartbeat' => 0,
        ]));

        $channelBefore = $connection->channel();

        $this->harness->info('Pausing broker after the publisher channel is open, before confirm wait');
        $this->harness->broker('pause');

        $elapsed = (float) $this->harness->withRetryDefaults(3, 500, function () use ($connection): float {
            return $this->harness->milliseconds(function () use ($connection): void {
                try {
                    $connection->publish(body: 'direct-timeout');
                    self::fail('Expected direct publish to time out while the broker is paused');
                } catch (TransportException $exception) {
                    $this->harness->info('Direct publish timed out: ' . $exception->getMessage());
                }
            });
        });

        self::assertLessThan(4500, $elapsed);
        self::assertSame(0, $this->harness->logger()->countRetryLogs());
        self::assertSame($channelBefore, $this->harness->publisherChannel($connection));

        $this->harness->broker('unpause');

        self::assertLessThanOrEqual(1, $connection->countMessagesInQueues());
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

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
}

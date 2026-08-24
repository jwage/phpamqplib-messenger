<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Throwable;

final class HeartbeatStall
{
    public const string DESCRIPTION = 'A heartbeat-enabled connection recovers after the broker is paused long enough to miss heartbeats.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('heartbeat_stall');
        $connection = $harness->connect($harness->topology($name, extra: [
            'heartbeat' => 1,
            'read_timeout' => 3,
            'write_timeout' => 3,
            'rpc_timeout' => 3,
        ]));

        $connection->publish(body: 'before-stall');
        $harness->assertSame(1, $connection->countMessagesInQueues(), 'control publish should succeed before the stall');

        $harness->info('Pausing broker so heartbeat frames cannot be answered');
        $harness->broker('pause');

        $harness->withRetryDefaults(0, 0, static function () use ($harness, $connection): void {
            try {
                $connection->publish(body: 'during-stall');
                $harness->fail('Expected publish to fail while the broker is paused');
            } catch (Throwable $exception) {
                $harness->info('Publish failed during stall: ' . $exception->getMessage());
            }
        });

        $harness->broker('unpause');
        $harness->waitUntilReady();

        $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'after-stall');
        });

        $harness->assertTrue(
            $connection->countMessagesInQueues() >= 2,
            'publishes before and after the heartbeat stall should both be in the queue',
        );
        $harness->info('Publish recovered after the heartbeat stall');
    }
}

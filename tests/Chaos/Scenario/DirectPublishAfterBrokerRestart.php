<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;

final class DirectPublishAfterBrokerRestart
{
    public const string DESCRIPTION = 'A later direct publish recovers after the broker restarts.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('direct_restart');
        $connection = $harness->connect($harness->topology($name));

        $connection->publish(body: 'before-restart');
        $harness->assertSame(1, $connection->countMessagesInQueues(), 'control publish should succeed before restart');

        $harness->info('Restarting broker after a successful direct publish');
        $harness->broker('restart');
        $harness->waitUntilReady();

        $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'after-restart');
        });

        $harness->assertTrue(
            $connection->countMessagesInQueues() >= 2,
            'publishes before and after restart should both be in the queue',
        );
        $harness->info('Direct publish recovered after broker restart');
    }
}

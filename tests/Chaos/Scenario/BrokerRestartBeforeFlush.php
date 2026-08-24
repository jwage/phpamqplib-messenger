<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;

final class BrokerRestartBeforeFlush
{
    public const string DESCRIPTION = 'A retained batch still flushes after the broker restarts.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('restart_before');
        $connection = $harness->connect($harness->topology($name));

        $connection->publish(body: 'one', batchSize: 3);
        $connection->publish(body: 'two', batchSize: 3);
        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'messages should remain buffered until flush');

        $harness->info('Restarting broker before flush');
        $harness->broker('restart');
        $harness->waitUntilReady();

        $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->flush();
        });

        $harness->assertSame(0, $harness->pendingBatchSize($connection), 'flush should clear the batch after reconnect');
        $harness->assertSame(2, $connection->countMessagesInQueues(), 'both buffered messages should arrive once the broker is back');
        $harness->info('Retained batch flushed after broker restart');
    }
}

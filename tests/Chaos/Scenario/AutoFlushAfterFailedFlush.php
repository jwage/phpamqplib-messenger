<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Throwable;

final class AutoFlushAfterFailedFlush
{
    public const string DESCRIPTION = 'After a failed auto-flush, a later publish still flushes the retained batch.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('auto_flush');
        $connection = $harness->connect($harness->topology($name));

        $connection->publish(body: 'one', batchSize: 2);
        $harness->assertSame(1, $harness->pendingBatchSize($connection), 'the first message should remain buffered');

        $harness->info('Stopping broker so auto-flush fails at the batch threshold');
        $harness->broker('stop');

        $harness->withRetryDefaults(0, 0, static function () use ($harness, $connection): void {
            try {
                $connection->publish(body: 'two', batchSize: 2);
                $harness->fail('Expected auto-flush to fail while the broker is stopped');
            } catch (Throwable $exception) {
                $harness->info('Auto-flush failed as expected: ' . $exception->getMessage());
            }
        });

        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'failed auto-flush must keep both messages');

        $harness->broker('start');
        $harness->waitUntilReady();

        $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'three', batchSize: 2);
        });

        $harness->assertSame(0, $harness->pendingBatchSize($connection), 'crossing the threshold again must auto-flush the retained batch');
        $harness->assertSame(3, $connection->countMessagesInQueues(), 'all three messages should be enqueued');
        $harness->info('Auto-flush recovered the retained batch after the broker returned');
    }
}

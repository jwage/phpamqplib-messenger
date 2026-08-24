<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Throwable;

final class RetainedBatchBeforeDirect
{
    public const string DESCRIPTION = 'A failed retained batch is flushed before a later direct publish.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('retained');
        $connection = $harness->connect($harness->topology($name));

        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'batch should remain buffered');

        $harness->info('Stopping broker so the first flush fails');
        $harness->broker('stop');

        $harness->withRetryDefaults(0, 0, static function () use ($harness, $connection): void {
            try {
                $connection->flush();
                $harness->fail('Expected flush to fail while the broker is stopped');
            } catch (Throwable $exception) {
                $harness->info('Flush failed as expected: ' . $exception->getMessage());
            }
        });

        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'failed flush must keep the batch');

        $harness->broker('start');
        $harness->waitUntilReady();

        $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'direct-three', batchSize: 1);
        });

        $harness->assertSame(0, $harness->pendingBatchSize($connection), 'direct publish should flush the retained batch first');
        $harness->assertSame(3, $connection->countMessagesInQueues(), 'both retained messages and the newer direct publish should be enqueued');
        $harness->info('Retained batch flushed before the newer direct publish');
    }
}

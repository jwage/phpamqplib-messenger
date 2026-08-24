<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Jwage\PhpAmqpLibMessengerBundle\Transport\PublisherNack;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

use function sprintf;

final class BatchNackOverflow
{
    public const string DESCRIPTION = 'A batch flush NACK from queue overflow is not retried or auto-replayed.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('batch_nack');
        $connection = $harness->connect($harness->topology($name, [
            'arguments' => [
                'x-max-length' => 1,
                'x-overflow' => 'reject-publish',
            ],
        ]));

        $connection->publish(body: 'kept');
        $harness->assertSame(1, $connection->countMessagesInQueues(), 'the overflow queue should hold the first message');

        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'the overflowing batch should still be buffered');

        $elapsed = (float) $harness->withRetryDefaults(3, 500, static function () use ($harness, $connection): float {
            return $harness->milliseconds(static function () use ($harness, $connection): void {
                try {
                    $connection->flush();
                    $harness->fail('Expected the overflowing batch flush to fail with a NACK');
                } catch (TransportException $exception) {
                    $harness->assertInstanceOf(
                        PublisherNack::class,
                        $exception->getPrevious(),
                        'the transport exception should wrap PublisherNack',
                    );
                } catch (Throwable $exception) {
                    $harness->fail('Unexpected exception: ' . $exception::class . ': ' . $exception->getMessage());
                }
            });
        });

        $harness->assertTrue(
            $elapsed < 400,
            sprintf('batch NACK should fail immediately without retry backoff, took %.1fms', $elapsed),
        );
        $harness->assertSame(
            0,
            $harness->logger()->countRetryLogs(),
            'RetryFactory should not log retries for a broker NACK',
        );
        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'a batch NACK must keep the buffer instead of auto-replaying');
        $harness->assertSame(1, $connection->countMessagesInQueues(), 'overflow NACK must not enqueue the rejected batch');
        $harness->info(sprintf('Batch NACK failed in %.1fms without retry; queue still has 1 message', $elapsed));
    }
}

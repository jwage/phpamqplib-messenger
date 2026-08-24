<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

final class MemoryAlarmFlush
{
    public const string DESCRIPTION = 'A broker memory alarm fails a batch flush and the retained batch still publishes afterward.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('memory_flush');
        $connection = $harness->connect($harness->topology($name));

        $connection->channel();
        $connection->publish(body: 'batch-one', batchSize: 3);
        $connection->publish(body: 'batch-two', batchSize: 3);
        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'messages should remain buffered until flush');

        $harness->info('Forcing a RabbitMQ memory alarm before flush');
        $harness->broker('memory-alarm');

        try {
            $connection->flush();
            $harness->fail('Expected flush to fail while the broker reports a memory alarm');
        } catch (TransportException $exception) {
            $previous = $exception->getPrevious();
            $harness->assertTrue(
                $previous instanceof AMQPConnectionBlockedException
                    || $exception->getMessage() !== '',
                'blocked flush should surface as a transport failure, not succeed',
            );
            $harness->info('Flush failed during alarm: ' . $exception->getMessage());
        } catch (AMQPConnectionBlockedException $exception) {
            $harness->info('Flush failed during alarm: ' . $exception->getMessage());
        } catch (Throwable $exception) {
            $harness->info('Flush failed during alarm: ' . $exception::class . ': ' . $exception->getMessage());
        }

        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'a blocked flush must keep the batch');

        $harness->broker('memory-ok');
        $harness->waitUntilReady();

        $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->flush();
        });

        $harness->assertSame(0, $harness->pendingBatchSize($connection), 'flush should clear the retained batch after the alarm');
        $harness->assertTrue(
            $connection->countMessagesInQueues() >= 2,
            'both buffered messages should arrive once the memory alarm clears',
        );
        $harness->info('Retained batch flushed after the memory alarm cleared');
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

final class ConfirmTimeoutRewait
{
    public const string DESCRIPTION = 'A live confirm timeout re-waits the same channel instead of republishing.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('confirm_timeout');
        $connection = $harness->connect($harness->topology($name, extra: [
            'confirm_timeout' => 1,
            'read_timeout' => 3,
            'write_timeout' => 3,
            'heartbeat' => 0,
        ]));

        $connection->publish(body: 'one', batchSize: 3);
        $connection->publish(body: 'two', batchSize: 3);
        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'both messages should still be buffered');

        $harness->info('Pausing broker after the batch is buffered, before confirm wait');
        $harness->broker('pause');

        try {
            $connection->flush();
            $harness->fail('Expected flush to time out while the broker is paused');
        } catch (TransportException $exception) {
            $harness->info('Flush timed out: ' . $exception->getMessage());
        } catch (Throwable $exception) {
            $harness->fail('Unexpected exception: ' . $exception::class . ': ' . $exception->getMessage());
        }

        $harness->assertSame(2, $harness->pendingBatchSize($connection), 'the batch must stay owned after a live confirm timeout');
        $harness->assertTrue(
            $harness->pendingConfirmChannel($connection) !== null,
            'the original confirm channel must be kept so the next flush re-waits instead of publishing again',
        );

        $harness->broker('unpause');
        $connection->flush();

        $harness->assertSame(0, $harness->pendingBatchSize($connection), 'flush should clear the retained batch after confirms arrive');
        $harness->assertSame(2, $connection->countMessagesInQueues(), 're-waiting must not duplicate the two published messages');
        $harness->info('Re-wait confirmed both messages once');
    }
}

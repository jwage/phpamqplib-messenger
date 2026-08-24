<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

use function sprintf;

final class DirectConfirmTimeoutRewait
{
    public const string DESCRIPTION = 'A direct-publish confirm timeout keeps the channel and does not republish.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('direct_confirm_timeout');
        $connection = $harness->connect($harness->topology($name, extra: [
            'confirm_timeout' => 1,
            'read_timeout' => 3,
            'write_timeout' => 3,
            'heartbeat' => 0,
        ]));

        $channelBefore = $connection->channel();

        $harness->info('Pausing broker after the publisher channel is open, before confirm wait');
        $harness->broker('pause');

        $elapsed = (float) $harness->withRetryDefaults(3, 500, static function () use ($harness, $connection): float {
            return $harness->milliseconds(static function () use ($harness, $connection): void {
                try {
                    $connection->publish(body: 'direct-timeout');
                    $harness->fail('Expected direct publish to time out while the broker is paused');
                } catch (TransportException $exception) {
                    $harness->info('Direct publish timed out: ' . $exception->getMessage());
                } catch (Throwable $exception) {
                    $harness->fail('Unexpected exception: ' . $exception::class . ': ' . $exception->getMessage());
                }
            });
        });

        $harness->assertTrue(
            $elapsed < 4500,
            sprintf('a single paused-broker timeout must not nest the default retry budget, took %.1fms', $elapsed),
        );
        $harness->assertSame(
            0,
            $harness->logger()->countRetryLogs(),
            'RetryFactory should not log retries for a live confirm timeout',
        );
        $harness->assertSame(
            $channelBefore,
            $harness->publisherChannel($connection),
            'the original publisher channel must be kept so a later wait can re-wait instead of publishing again',
        );

        $harness->broker('unpause');

        $count = $connection->countMessagesInQueues();
        $harness->assertTrue(
            $count <= 1,
            sprintf('a single timed-out publish must not duplicate (queue has %d)', $count),
        );
        $harness->info(sprintf('Direct confirm timeout kept the channel; queue has %d message(s)', $count));
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Throwable;

use function sprintf;

final class BrokerRestartDuringFlush
{
    public const string DESCRIPTION = 'An in-flight flush survives a broker restart (at-least-once, never a silent drop).';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('restart_during');
        $connection = $harness->connect($harness->topology($name));

        $connection->publish(body: 'one', batchSize: 3);
        $connection->publish(body: 'two', batchSize: 3);

        $harness->info('Restarting broker during flush');
        $harness->brokerLater(0.2, 'restart');

        $harness->withRetryDefaults(8, 250, static function () use ($harness, $connection): void {
            try {
                $connection->flush();
            } catch (Throwable $exception) {
                $harness->info('Flush threw during restart: ' . $exception->getMessage());
            }
        });

        $harness->waitUntilReady();

        if ($harness->pendingBatchSize($connection) > 0) {
            $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
                $connection->flush();
            });
        }

        $count = $connection->countMessagesInQueues();
        $harness->assertTrue(
            $count >= 2,
            sprintf('expected at-least-once delivery of 2 messages, queue has %d (silent drop?)', $count),
        );
        $harness->assertTrue(
            $count <= 4,
            sprintf('expected at-least-once of 2 messages, not unbounded duplicates (%d)', $count),
        );

        if ($count > 2) {
            $harness->info(sprintf('Observed %d messages; duplicates are allowed for at-least-once recovery', $count));
        } else {
            $harness->info('Recovered without duplicates');
        }
    }
}

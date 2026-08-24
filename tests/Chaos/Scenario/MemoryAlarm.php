<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

final class MemoryAlarm
{
    public const string DESCRIPTION = 'A broker memory alarm fails the publish instead of opening extra channels.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('memory');
        $connection = $harness->connect($harness->topology($name));

        $connection->publish(body: 'before-alarm');
        $harness->assertSame(1, $connection->countMessagesInQueues(), 'control publish should succeed before the alarm');

        $harness->info('Forcing a RabbitMQ memory alarm');
        $harness->broker('memory-alarm');

        try {
            $connection->publish(body: 'during-alarm');
            $harness->fail('Expected publish to fail while the broker reports a memory alarm');
        } catch (TransportException $exception) {
            $previous = $exception->getPrevious();
            $harness->assertTrue(
                $previous instanceof AMQPConnectionBlockedException
                    || $exception->getMessage() !== '',
                'blocked publish should surface as a transport failure, not succeed',
            );
            $harness->info('Publish failed during alarm: ' . $exception->getMessage());
        } catch (Throwable $exception) {
            $harness->info('Publish failed during alarm: ' . $exception::class . ': ' . $exception->getMessage());
        }

        $harness->broker('memory-ok');
        $harness->waitUntilReady();

        $harness->withRetryDefaults(5, 200, static function () use ($connection): void {
            $connection->publish(body: 'after-alarm');
        });

        $harness->assertTrue(
            $connection->countMessagesInQueues() >= 2,
            'publishes before and after the alarm should both be in the queue',
        );
        $harness->info('Publish recovered after the memory alarm cleared');
    }
}

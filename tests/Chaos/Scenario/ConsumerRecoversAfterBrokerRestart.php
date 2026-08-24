<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;

final class ConsumerRecoversAfterBrokerRestart
{
    public const string DESCRIPTION = 'A consumer can ack a persistent message after the broker restarts.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('consumer_restart');
        $connection = $harness->connect($harness->topology($name, ['wait_timeout' => 2]));

        $connection->publish(body: 'survive-restart');
        $harness->assertSame(1, $connection->countMessagesInQueues(), 'the message should be queued before restart');

        $harness->info('Restarting broker with a persistent unconsumed message');
        $harness->broker('restart');
        $harness->waitUntilReady();

        $envelope = $harness->withRetryDefaults(5, 200, static function () use ($harness, $connection, $name): AmqpEnvelope {
            return $harness->consumeOne($connection, $name);
        });

        if (! $envelope instanceof AmqpEnvelope) {
            $harness->fail('expected a delivery after restart');
        }

        $envelope->ack();

        $harness->assertSame(0, $connection->countMessagesInQueues(), 'acking after restart must remove the redelivered message');
        $harness->info('Consumed and acked the message after broker restart');
    }
}

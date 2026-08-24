<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;

final class Smoke
{
    public const string DESCRIPTION = 'Publish one message and see it land on a live broker.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('smoke');
        $connection = $harness->connect($harness->topology($name));

        $connection->publish(body: 'chaos-smoke');

        $harness->assertSame(1, $connection->countMessagesInQueues(), 'smoke publish should enqueue one message');
        $harness->info('Published and counted 1 message on ' . $name);
    }
}

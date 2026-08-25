<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

class SmokeTest extends ChaosTestCase
{
    public function testPublishEnqueuesOneMessage(): void
    {
        $name       = $this->harness->topologyName('smoke');
        $connection = $this->harness->connect($this->harness->topology($name));

        $connection->publish(body: 'chaos-smoke');

        self::assertSame(1, $connection->countMessagesInQueues());
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use function dirname;

class SslPublishTest extends ChaosTestCase
{
    public function testPublishAndConsumeOverTls(): void
    {
        $name       = $this->harness->topologyName('ssl');
        $connection = $this->harness->connect($this->harness->topology($name, ['wait_timeout' => 2], [
            'ssl' => [
                'cafile' => dirname(__DIR__) . '/fixtures/ssl/ca.pem',
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]), $this->harness->sslDsn());

        $connection->publish(body: 'over-tls');
        self::assertSame(1, $connection->countMessagesInQueues());

        $envelope = $this->harness->consumeOne($connection, $name);
        $envelope->ack();
        self::assertSame(0, $connection->countMessagesInQueues());
    }
}

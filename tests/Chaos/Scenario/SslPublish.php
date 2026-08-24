<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Harness;

use function dirname;

final class SslPublish
{
    public const string DESCRIPTION = 'A phpamqplibs:// publish succeeds against the compose TLS listener.';

    public static function run(Harness $harness): void
    {
        $name       = $harness->topologyName('ssl');
        $connection = $harness->connect($harness->topology($name, ['wait_timeout' => 2], [
            'ssl' => [
                'cafile' => dirname(__DIR__, 2) . '/fixtures/ssl/ca.pem',
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]), $harness->sslDsn());

        $connection->publish(body: 'over-tls');
        $harness->assertSame(1, $connection->countMessagesInQueues(), 'TLS publish should enqueue one message');

        $envelope = $harness->consumeOne($connection, $name);
        $envelope->ack();
        $harness->assertSame(0, $connection->countMessagesInQueues(), 'the TLS-published message should be consumable');
        $harness->info('Published and consumed over TLS');
    }
}

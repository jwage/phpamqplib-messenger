<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Functional\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;

final class StalledWriteConnectionFactory extends AmqpConnectionFactory
{
    public StalledWriteConnection|null $created = null;

    public function create(ConnectionConfig $connectionConfig): AMQPStreamConnection
    {
        $this->created = new StalledWriteConnection(
            $connectionConfig->host,
            $connectionConfig->port,
            $connectionConfig->user,
            $connectionConfig->password,
            $connectionConfig->vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            3.0,
            5.0,
            null,
            false,
            $connectionConfig->heartbeat,
            5.0,
        );

        return $this->created;
    }
}

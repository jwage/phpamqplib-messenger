<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory as BaseAMQPConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;

use function assert;

class AmqpConnectionFactory
{
    public function create(ConnectionConfig $connectionConfig): AMQPStreamConnection
    {
        $config = $connectionConfig->getAMQPConnectionConfig();

        $connection = BaseAMQPConnectionFactory::create($config);
        assert($connection instanceof AMQPStreamConnection);

        return $connection;
    }
}

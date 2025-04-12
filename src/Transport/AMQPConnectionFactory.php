<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory as BaseAMQPConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;

use function assert;

class AMQPConnectionFactory
{
    public function create(ConnectionConfig $connectionConfig): AMQPStreamConnection
    {
        $config = new AMQPConnectionConfig();
        $config->setIsLazy(true);
        $config->setHost($connectionConfig->host);
        $config->setPort($connectionConfig->port);
        $config->setUser($connectionConfig->user);
        $config->setPassword($connectionConfig->password);
        $config->setVhost($connectionConfig->vhost);
        $config->setInsist($connectionConfig->insist);
        $config->setLoginMethod($connectionConfig->loginMethod);
        $config->setLocale($connectionConfig->locale);
        $config->setConnectionTimeout($connectionConfig->connectionTimeout);
        $config->setReadTimeout($connectionConfig->readTimeout);
        $config->setWriteTimeout($connectionConfig->writeTimeout);
        $config->setChannelRPCTimeout($connectionConfig->channelRPCTimeout);
        $config->setHeartbeat($connectionConfig->heartbeat);
        $config->setKeepalive($connectionConfig->keepalive);

        if ($connectionConfig->cacert !== null) {
            $config->setIsSecure(true);
            $config->setSslCaCert($connectionConfig->cacert);
        }

        $connection = BaseAMQPConnectionFactory::create($config);
        assert($connection instanceof AMQPStreamConnection);

        return $connection;
    }
}

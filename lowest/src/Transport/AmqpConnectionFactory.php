<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory as BaseAMQPConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;

use function assert;

class AmqpConnectionFactory
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
        $config->setConnectionTimeout($connectionConfig->connectTimeout);
        $config->setReadTimeout($connectionConfig->readTimeout);
        $config->setWriteTimeout($connectionConfig->writeTimeout);
        $config->setChannelRPCTimeout($connectionConfig->rpcTimeout);
        $config->setHeartbeat($connectionConfig->heartbeat);
        $config->setKeepalive($connectionConfig->keepalive);
        $config->setConnectionName($connectionConfig->connectionName);

        if ($connectionConfig->ssl !== null) {
            $config->setIsSecure(true);

            if ($connectionConfig->ssl->cafile !== null) {
                $config->setSslCaCert($connectionConfig->ssl->cafile);
            }

            if ($connectionConfig->ssl->capath !== null) {
                $config->setSslCaPath($connectionConfig->ssl->capath);
            }

            if ($connectionConfig->ssl->localCert !== null) {
                $config->setSslCert($connectionConfig->ssl->localCert);
            }

            if ($connectionConfig->ssl->localPk !== null) {
                $config->setSslKey($connectionConfig->ssl->localPk);
            }

            if ($connectionConfig->ssl->verifyPeer !== null) {
                $config->setSslVerify($connectionConfig->ssl->verifyPeer);
            }

            if ($connectionConfig->ssl->verifyPeerName !== null) {
                $config->setSslVerifyName($connectionConfig->ssl->verifyPeerName);
            }

            if ($connectionConfig->ssl->passphrase !== null) {
                $config->setSslPassPhrase($connectionConfig->ssl->passphrase);
            }

            if ($connectionConfig->ssl->ciphers !== null) {
                $config->setSslCiphers($connectionConfig->ssl->ciphers);
            }

            if ($connectionConfig->ssl->securityLevel !== null) {
                $config->setSslSecurityLevel($connectionConfig->ssl->securityLevel);
            }

            if ($connectionConfig->ssl->cryptoMethod !== null) {
                $config->setSslCryptoMethod($connectionConfig->ssl->cryptoMethod);
            }
        }

        $connection = BaseAMQPConnectionFactory::create($config);
        assert($connection instanceof AMQPStreamConnection);

        return $connection;
    }
}

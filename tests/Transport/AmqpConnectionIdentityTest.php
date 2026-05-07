<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionIdentity;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\SslConfig;

final class AmqpConnectionIdentityTest extends TestCase
{
    public function testEquivalentConnectionConfigsHaveSameIdentity(): void
    {
        $identityA = AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig());
        $identityB = AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig(
            host: 'localhost',
            port: 5672,
            user: 'guest',
            password: 'guest',
            vhost: '/',
            connectTimeout: 3.0,
            readTimeout: 3.0,
            writeTimeout: 3.0,
            rpcTimeout: 3.0,
            heartbeat: 0,
            keepalive: true,
            connectionName: '',
        ));

        self::assertSame($identityA->toString(), $identityB->toString());
    }

    public function testTransportLevelOptionsDoNotAffectIdentity(): void
    {
        $identityA = AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig(
            exchange: new ExchangeConfig(name: 'exchange_1'),
            queues: ['queue_1' => new QueueConfig(name: 'queue_1')],
        ));
        $identityB = AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig(
            exchange: new ExchangeConfig(name: 'exchange_2'),
            queues: ['queue_2' => new QueueConfig(name: 'queue_2')],
        ));

        self::assertSame($identityA->toString(), $identityB->toString());
    }

    public function testConnectionNameAffectsIdentity(): void
    {
        $identityA = AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig(connectionName: 'consumer'));
        $identityB = AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig(connectionName: 'publisher'));

        self::assertNotSame($identityA->toString(), $identityB->toString());
    }

    public function testEquivalentSslOptionsHaveSameIdentity(): void
    {
        $identityA = AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig(
            ssl: new SslConfig(
                cafile: '/tmp/ca.pem',
                capath: '/tmp/certs',
                localCert: '/tmp/cert.pem',
                localPk: '/tmp/key.pem',
                verifyPeer: true,
                verifyPeerName: true,
                passphrase: 'secret',
                ciphers: 'TLS_AES_256_GCM_SHA384',
                securityLevel: 2,
                cryptoMethod: 4,
            ),
        ));
        $identityB = AmqpConnectionIdentity::fromConnectionConfig(new ConnectionConfig(
            ssl: new SslConfig(
                cryptoMethod: 4,
                securityLevel: 2,
                ciphers: 'TLS_AES_256_GCM_SHA384',
                passphrase: 'secret',
                verifyPeerName: true,
                verifyPeer: true,
                localPk: '/tmp/key.pem',
                localCert: '/tmp/cert.pem',
                capath: '/tmp/certs',
                cafile: '/tmp/ca.pem',
            ),
        ));

        self::assertSame($identityA->toString(), $identityB->toString());
    }
}

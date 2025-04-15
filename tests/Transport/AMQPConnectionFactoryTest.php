<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\SslConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class AMQPConnectionFactoryTest extends TestCase
{
    private AMQPConnectionFactory $amqpConnectionFactory;

    public function testCreate(): void
    {
        $connectionConfig = new ConnectionConfig(
            host: 'chimpanzee.rmq.cloudamqp.com',
            port: 5671,
            user: 'qsnsxjkx',
            password: 'QvsKk_6nLzV8X0eYk2zTQD2PLeFPeIz3',
            vhost: 'qsnsxjkx',
            ssl: new SslConfig(
                cafile: 'certs/isrgrootx1.pem',
                capath: 'certs',
                localCert: 'certs/local.pem',
                localPk: 'certs/local.key',
                verifyPeer: true,
                verifyPeerName: true,
                passphrase: 'passphrase',
                ciphers: 'ciphers',
                securityLevel: 1,
                cryptoMethod: 1,
            ),
        );

        $connection = $this->amqpConnectionFactory->create($connectionConfig);

        self::assertInstanceOf(AMQPStreamConnection::class, $connection);
        self::assertFalse($connection->isConnected());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->amqpConnectionFactory = new AMQPConnectionFactory();
    }
}

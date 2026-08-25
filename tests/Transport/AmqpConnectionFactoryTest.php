<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\SslConfig;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use ReflectionProperty;

class AmqpConnectionFactoryTest extends TestCase
{
    private AmqpConnectionFactory $amqpConnectionFactory;

    public function testCreate(): void
    {
        $connectionConfig = new ConnectionConfig(
            host: 'chimpanzee.rmq.cloudamqp.com',
            port: 5671,
            user: 'qsnsxjkx',
            password: 'QvsKk_6nLzV8X0eYk2zTQD2PLeFPeIz3',
            vhost: 'qsnsxjkx',
            insist: true,
            loginMethod: AMQPConnectionConfig::AUTH_PLAIN,
            locale: 'fr_FR',
            connectTimeout: 9.0,
            readTimeout: 8.0,
            writeTimeout: 7.0,
            rpcTimeout: 6.0,
            heartbeat: 10,
            keepalive: true,
            connectionName: 'ssl-conn',
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

        $config = (new ReflectionProperty(AbstractConnection::class, 'config'))->getValue($connection);
        self::assertInstanceOf(AMQPConnectionConfig::class, $config);
        self::assertTrue($config->isLazy());
        self::assertSame('chimpanzee.rmq.cloudamqp.com', $config->getHost());
        self::assertSame(5671, $config->getPort());
        self::assertSame('qsnsxjkx', $config->getUser());
        self::assertSame('QvsKk_6nLzV8X0eYk2zTQD2PLeFPeIz3', $config->getPassword());
        self::assertSame('qsnsxjkx', $config->getVhost());
        self::assertTrue($config->isInsist());
        self::assertSame(AMQPConnectionConfig::AUTH_PLAIN, $config->getLoginMethod());
        self::assertSame('fr_FR', $config->getLocale());
        self::assertSame(9.0, $config->getConnectionTimeout());
        self::assertSame(8.0, $config->getReadTimeout());
        self::assertSame(7.0, $config->getWriteTimeout());
        self::assertSame(6.0, $config->getChannelRPCTimeout());
        self::assertSame(10, $config->getHeartbeat());
        self::assertTrue($config->isKeepalive());
        self::assertSame('ssl-conn', $config->getConnectionName());
        self::assertTrue($config->isSecure());
        self::assertSame('certs/isrgrootx1.pem', $config->getSslCaCert());
        self::assertSame('certs', $config->getSslCaPath());
        self::assertSame('certs/local.pem', $config->getSslCert());
        self::assertSame('certs/local.key', $config->getSslKey());
        self::assertTrue($config->getSslVerify());
        self::assertTrue($config->getSslVerifyName());
        self::assertSame('passphrase', $config->getSslPassPhrase());
        self::assertSame('ciphers', $config->getSslCiphers());
        self::assertSame(1, $config->getSslSecurityLevel());
        self::assertSame(1, $config->getSslCryptoMethod());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->amqpConnectionFactory = new AmqpConnectionFactory();
    }
}

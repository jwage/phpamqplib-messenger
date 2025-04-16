<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\SslConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;

class ConnectionFactoryTest extends TestCase
{
    private DsnParser $dsnParser;

    private RetryFactory $retryFactory;

    private AmqpConnectionFactory $amqpConnectionFactory;

    private ConnectionFactory $connectionFactory;

    public function testFromDsn(): void
    {
        $connection = $this->connectionFactory->fromDsn('phpamqplibs://guest:guest@localhost:5672?ssl[cafile]=/path/to/cacert.pem', [
            'exchange' => ['name' => 'exchange_name'],
            'queues' => ['queue_name' => []],
        ]);

        self::assertFalse($connection->isConnected());

        $connectionConfig = $connection->getConfig();

        self::assertSame('guest', $connectionConfig->user);
        self::assertSame('guest', $connectionConfig->password);
        self::assertSame('localhost', $connectionConfig->host);
        self::assertSame(5672, $connectionConfig->port);
        self::assertSame('/', $connectionConfig->vhost);
        self::assertInstanceOf(SslConfig::class, $connectionConfig->ssl);
        self::assertEquals('/path/to/cacert.pem', $connectionConfig->ssl->cafile);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dsnParser             = new DsnParser();
        $this->retryFactory          = new RetryFactory();
        $this->amqpConnectionFactory = new AmqpConnectionFactory();

        $this->connectionFactory = new ConnectionFactory(
            $this->dsnParser,
            $this->retryFactory,
            $this->amqpConnectionFactory,
        );
    }
}

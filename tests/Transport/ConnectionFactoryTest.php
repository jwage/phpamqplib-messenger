<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;

class ConnectionFactoryTest extends TestCase
{
    private DsnParser $dsnParser;

    private RetryFactory $retryFactory;

    private AMQPConnectionFactory $amqpConnectionFactory;

    private ConnectionFactory $connectionFactory;

    public function testFromDsn(): void
    {
        $connection = $this->connectionFactory->fromDsn('amqp://guest:guest@localhost:5672?cacert=/path/to/cacert.pem', [
            'exchange' => ['name' => 'exchange_name'],
            'queues' => ['queue_name' => []],
        ]);

        self::assertFalse($connection->isConnected());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dsnParser             = new DsnParser();
        $this->retryFactory          = new RetryFactory();
        $this->amqpConnectionFactory = new AMQPConnectionFactory();

        $this->connectionFactory = new ConnectionFactory(
            $this->dsnParser,
            $this->retryFactory,
            $this->amqpConnectionFactory,
        );
    }
}

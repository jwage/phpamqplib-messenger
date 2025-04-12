<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;

class ConnectionTest extends TestCase
{
    private RetryFactory $retryFactory;

    /** @var AMQPConnectionFactory&MockObject */
    private AMQPConnectionFactory $amqpConnectionFactory;

    /** @var AMQPStreamConnection&MockObject */
    private AMQPStreamConnection $amqpConnection;

    /** @var AMQPChannel&MockObject */
    private AMQPChannel $amqpChannel;

    private Connection $connection;

    public function testDisconnect(): void
    {
        $this->connection->connect();
        $this->connection->channel();

        $this->amqpChannel->expects(self::once())
            ->method('close');

        $this->amqpConnection->expects(self::once())
            ->method('close');

        $this->connection->disconnect();
    }

    public function testReconnect(): void
    {
        $this->connection->connect();
        $this->connection->channel();

        $this->amqpChannel->expects(self::once())
            ->method('close');

        $this->amqpConnection->expects(self::once())
            ->method('close');

        $this->connection->reconnect();
    }

    public function testSetup(): void
    {
        $this->amqpChannel->expects(self::exactly(2))
            ->method('exchange_declare')
            ->with(...self::withConsecutive(
                [
                    'exchange_name',
                    'fanout',
                    false,
                    true,
                    false,
                    false,
                    true,
                    new AMQPTable([]),
                ],
                [
                    'delays',
                    'direct',
                    false,
                    true,
                    false,
                    false,
                    true,
                    new AMQPTable([]),
                ],
            ));

        $this->amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->with(
                queue: 'queue_name',
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: false,
                nowait: true,
                arguments: new AMQPTable([]),
            );

        $this->amqpChannel->expects(self::once())
            ->method('queue_bind')
            ->with(
                queue: 'queue_name',
                exchange: 'exchange_name',
                routing_key: '',
                nowait: true,
            );

        $this->connection->setup();
    }

    public function testChannel(): void
    {
        self::assertSame($this->amqpChannel, $this->connection->channel());
        self::assertSame($this->amqpChannel, $this->connection->channel());
    }

    public function testGet(): void
    {
        $amqpEnvelope = $this->connection->get('queue_name');

        self::assertNull($amqpEnvelope);
    }

    public function testPublish(): void
    {
        $body = 'test body';

        $amqpMessage = new AMQPMessage(
            $body,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(['protocol' => 3]),
            ],
        );

        $this->amqpChannel->expects(self::once())
            ->method('basic_publish')
            ->with(
                body: $amqpMessage,
                exchange: 'exchange_name',
                routing_key: '',
            );

        $this->connection->publish(body: 'test body');
    }

    public function testPublishWithBatchSizeGreaterThanOne(): void
    {
        $body1 = 'test body 1';
        $body2 = 'test body 2';

        $amqpMessage1 = new AMQPMessage(
            $body1,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(['protocol' => 3]),
            ],
        );

        $amqpMessage2 = new AMQPMessage(
            $body2,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(['protocol' => 3]),
            ],
        );

        $this->amqpChannel->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $this->amqpChannel->expects(self::once())
            ->method('publish_batch');

        $this->amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(3);

        $this->connection->publish(body: $body1, batchSize: 2);
        $this->connection->publish(body: $body2, batchSize: 2);
    }

    public function testCountMessagesInQueues(): void
    {
        $this->amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->willReturn(['queue_name', 2]);

        self::assertSame(2, $this->connection->countMessagesInQueues());
    }

    public function testGetQueueNames(): void
    {
        self::assertSame(['queue_name'], $this->connection->getQueueNames());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->retryFactory = new RetryFactory();

        $this->amqpConnectionFactory = $this->createMock(AMQPConnectionFactory::class);

        $this->amqpConnection = $this->createMock(AMQPStreamConnection::class);

        $this->amqpChannel = $this->createMock(AMQPChannel::class);

        $this->amqpConnectionFactory->expects(self::any())
            ->method('create')
            ->willReturn($this->amqpConnection);

        $this->amqpConnection->expects(self::any())
            ->method('channel')
            ->willReturn($this->amqpChannel);

        $this->amqpChannel->expects(self::any())
            ->method('confirm_select');

        $this->connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $this->amqpConnectionFactory,
            connectionConfig: new ConnectionConfig(
                exchange: new ExchangeConfig(name: 'exchange_name'),
                queues: [
                    'queue_name' => new QueueConfig(),
                ],
            ),
        );
    }
}

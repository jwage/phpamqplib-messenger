<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\BindingConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Messenger\Exception\TransportException;
use Traversable;

use function iterator_to_array;

class ConnectionTest extends TestCase
{
    private RetryFactory $retryFactory;

    /** @var AMQPConnectionFactory&MockObject */
    private AmqpConnectionFactory $amqpConnectionFactory;

    /** @var AMQPStreamConnection&MockObject */
    private AMQPStreamConnection $amqpConnection;

    /** @var AMQPChannel&MockObject */
    private AMQPChannel $amqpChannel;

    private Connection $connection;

    public function testReconnect(): void
    {
        $this->connection->channel();

        $this->amqpConnection->expects(self::once())
            ->method('reconnect');

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
                routing_key: 'routing_key',
                nowait: true,
                arguments: new AMQPTable(['arg1' => 'value1', 'arg2' => 'value2']),
            );

        $this->connection->setup();
    }

    public function testChannel(): void
    {
        $this->amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($this->amqpChannel);

        $this->amqpChannel->expects(self::once())
            ->method('confirm_select');

        self::assertSame($this->amqpChannel, $this->connection->channel());
        self::assertSame($this->amqpChannel, $this->connection->channel());
    }

    public function testChannelWithConfirmDisabled(): void
    {
        $this->amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($this->amqpChannel);

        $this->amqpChannel->expects(self::never())
            ->method('confirm_select');

        $connection = $this->getTestConnection(new ConnectionConfig(confirmEnabled: false));

        self::assertSame($this->amqpChannel, $connection->channel());
        self::assertSame($this->amqpChannel, $connection->channel());
    }

    public function testGet(): void
    {
        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $this->connection->get('queue_name');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
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

        $this->amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $this->connection->publish(body: 'test body');
    }

    public function testPublishWithConfirmDisabled(): void
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

        $this->amqpChannel->expects(self::never())
            ->method('wait_for_pending_acks');

        $connection = $this->getTestConnection(new ConnectionConfig(
            exchange: new ExchangeConfig(name: 'exchange_name'),
            confirmEnabled: false,
        ));

        $connection->publish(body: 'test body');
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
            ->with(timeout: 5);

        $this->connection->publish(body: $body1, batchSize: 2);
        $this->connection->publish(body: $body2, batchSize: 2);
    }

    public function testFlush(): void
    {
        $this->amqpChannel->expects(self::once())
            ->method('publish_batch');

        $this->amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $this->connection->flush();
    }

    public function testFlushWithConfirmDisabled(): void
    {
        $connection = $this->getTestConnection(new ConnectionConfig(confirmEnabled: false));

        $this->amqpChannel->expects(self::once())
            ->method('publish_batch');

        $this->amqpChannel->expects(self::never())
            ->method('wait_for_pending_acks');

        $connection->flush();
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

    public function testWithRetrySuccess(): void
    {
        $count = 0;

        $check = $this->connection->withRetry(static function () use (&$count) {
            $count++;

            if ($count < 3) {
                throw new AMQPConnectionClosedException();
            }

            return 'test';
        }, waitTime: 0)->run();

        self::assertSame(3, $count);
        self::assertSame('test', $check);
    }

    public function testWithRetryFailure(): void
    {
        self::expectException(TransportException::class);
        self::expectExceptionMessage('test');

        $this->connection->withRetry(static function (): void {
            throw new AMQPConnectionClosedException('test');
        }, waitTime: 0)->run();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->retryFactory = new RetryFactory();

        $this->amqpConnectionFactory = $this->createMock(AmqpConnectionFactory::class);

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

        $this->connection = $this->getTestConnection();
    }

    private function getTestConnection(ConnectionConfig|null $connectionConfig = null): Connection
    {
        return new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $this->amqpConnectionFactory,
            connectionConfig: $connectionConfig ?? new ConnectionConfig(
                confirmEnabled: true,
                confirmTimeout: 5.0,
                exchange: new ExchangeConfig(name: 'exchange_name'),
                queues: [
                    'queue_name' => new QueueConfig(
                        name: 'queue_name',
                        bindings: [
                            'routing_key' => new BindingConfig(
                                routingKey: 'routing_key',
                                arguments: ['arg1' => 'value1', 'arg2' => 'value2'],
                            ),
                        ],
                    ),
                ],
            ),
        );
    }
}

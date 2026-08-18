<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\BindingConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\DelayConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
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

    private Connection $connection;

    /**
     * Creates a Connection with all stubs (no mock expectations possible).
     * Used for tests that don't need to verify interactions.
     */
    private function createConnectionWithStubs(ConnectionConfig|null $connectionConfig = null): Connection
    {
        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createStub(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);

        return new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig ?? $this->getDefaultConfig(),
        );
    }

    /**
     * Creates a Connection with a mock AMQPChannel for verifying channel interactions.
     *
     * @return array{Connection, AMQPChannel&MockObject}
     */
    private function createConnectionWithChannelMock(ConnectionConfig|null $connectionConfig = null): array
    {
        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpChannel->method('confirm_select');

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig ?? $this->getDefaultConfig(),
        );

        return [$connection, $amqpChannel];
    }

    /**
     * Creates a Connection with a mock AMQPStreamConnection for verifying connection interactions.
     *
     * @return array{Connection, AMQPStreamConnection&MockObject}
     */
    private function createConnectionWithConnectionMock(ConnectionConfig|null $connectionConfig = null): array
    {
        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel    = $this->createStub(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig ?? $this->getDefaultConfig(),
        );

        return [$connection, $amqpConnection];
    }

    /**
     * Creates a Connection with mock AMQPStreamConnection and AMQPChannel.
     * Does not pre-configure channel() or confirm_select() — tests must configure these.
     *
     * @return array{Connection, AMQPStreamConnection&MockObject, AMQPChannel&MockObject}
     */
    private function createConnectionWithAllMocks(ConnectionConfig|null $connectionConfig = null): array
    {
        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig ?? $this->getDefaultConfig(),
        );

        return [$connection, $amqpConnection, $amqpChannel];
    }

    /**
     * @return array{
     *     0: Connection,
     *     1: AMQPStreamConnection&MockObject,
     *     2: AMQPChannel&MockObject,
     *     3: AMQPChannel&MockObject,
     *     4: string,
     * }
     */
    private function createConnectionForDelayPublishReconnectTest(
        ConnectionConfig|null $connectionConfig = null,
    ): array {
        $connectionConfig ??= new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            confirmTimeout: 5.0,
            exchange: new ExchangeConfig(name: 'exchange_name'),
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel1   = $this->createMock(AMQPChannel::class);
        $amqpChannel2   = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2);
        $amqpConnection->expects(self::once())
            ->method('reconnect');

        $amqpChannel1->method('confirm_select');
        $amqpChannel2->method('confirm_select');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->channel();

        $delayQueueName = $connectionConfig->getDelayQueueName(5000, '', false);

        return [$connection, $amqpConnection, $amqpChannel1, $amqpChannel2, $delayQueueName];
    }

    private function expectSuccessfulDelayQueueSetupOnChannel(
        AMQPChannel&MockObject $amqpChannel,
        string $delayQueueName,
    ): void {
        $amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->with(
                queue: $delayQueueName,
                passive: false,
                durable: false,
                exclusive: false,
                auto_delete: true,
                nowait: false,
                arguments: new AMQPTable([
                    'x-message-ttl' => 5000,
                    'x-expires' => 15000,
                    'x-dead-letter-exchange' => 'exchange_name',
                    'x-dead-letter-routing-key' => '',
                ]),
            )
            ->willReturn([$delayQueueName, 0]);

        $amqpChannel->expects(self::once())
            ->method('queue_bind')
            ->with(
                queue: $delayQueueName,
                exchange: 'delays',
                routing_key: $delayQueueName,
                nowait: false,
            );

        $amqpChannel->expects(self::once())
            ->method('basic_publish');

        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);
    }

    private function runDelayPublishWithZeroWaitTime(Connection $connection): void
    {
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->publish(body: 'test body', delayInMs: 5000);
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    private function getDefaultConfig(): ConnectionConfig
    {
        return new ConnectionConfig(
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
        );
    }

    public function testClose(): void
    {
        [$connection, $amqpConnection] = $this->createConnectionWithConnectionMock();

        $amqpConnection->expects(self::once())
            ->method('close');

        $connection->channel();

        $connection->close();
    }

    public function testReconnect(): void
    {
        [$connection, $amqpConnection] = $this->createConnectionWithConnectionMock();

        $connection->channel();

        $amqpConnection->expects(self::once())
            ->method('reconnect');

        $connection->reconnect();
    }

    public function testSetup(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $amqpChannel->expects(self::exactly(2))
            ->method('exchange_declare')
            ->with(...self::withConsecutive(
                [
                    'exchange_name',
                    'fanout',
                    false,
                    true,
                    false,
                    false,
                    false,
                    new AMQPTable([]),
                ],
                [
                    'delays',
                    'direct',
                    false,
                    true,
                    false,
                    false,
                    false,
                    new AMQPTable([]),
                ],
            ));

        $amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->with(
                queue: 'queue_name',
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: false,
                nowait: false,
                arguments: new AMQPTable([]),
            );

        $amqpChannel->expects(self::once())
            ->method('queue_bind')
            ->with(
                queue: 'queue_name',
                exchange: 'exchange_name',
                routing_key: 'routing_key',
                nowait: false,
                arguments: new AMQPTable(['arg1' => 'value1', 'arg2' => 'value2']),
            );

        $connection->setup();
    }

    public function testSetupWithAutoSetupDisabled(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
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
        ));

        $amqpChannel->expects(self::exactly(2))
            ->method('exchange_declare')
            ->with(...self::withConsecutive(
                [
                    'exchange_name',
                    'fanout',
                    false,
                    true,
                    false,
                    false,
                    false,
                    new AMQPTable([]),
                ],
                [
                    'delays',
                    'direct',
                    false,
                    true,
                    false,
                    false,
                    false,
                    new AMQPTable([]),
                ],
            ));

        $amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->with(
                queue: 'queue_name',
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: false,
                nowait: false,
                arguments: new AMQPTable([]),
            );

        $amqpChannel->expects(self::once())
            ->method('queue_bind')
            ->with(
                queue: 'queue_name',
                exchange: 'exchange_name',
                routing_key: 'routing_key',
                nowait: false,
                arguments: new AMQPTable(['arg1' => 'value1', 'arg2' => 'value2']),
            );

        $connection->setup();
    }

    public function testSetupWithDelayDisabled(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            exchange: new ExchangeConfig(name: 'exchange_name'),
            delay: new DelayConfig(enabled: false),
        ));

        $amqpChannel->expects(self::once())
            ->method('exchange_declare')
            ->with(...self::withConsecutive(
                [
                    'exchange_name',
                    'fanout',
                    false,
                    true,
                    false,
                    false,
                    false,
                    new AMQPTable([]),
                ],
            ));

        $connection->setup();
    }

    public function testChannel(): void
    {
        [$connection, $amqpConnection, $amqpChannel] = $this->createConnectionWithAllMocks();

        $amqpConnection->method('isConnected')->willReturn(true);

        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);

        $amqpChannel->expects(self::once())
            ->method('confirm_select');

        self::assertSame($amqpChannel, $connection->channel());
        self::assertSame($amqpChannel, $connection->channel());
    }

    public function testChannelWithConfirmDisabled(): void
    {
        [$connection, $amqpConnection, $amqpChannel] = $this->createConnectionWithAllMocks(
            new ConnectionConfig(confirmEnabled: false),
        );

        $amqpConnection->method('isConnected')->willReturn(true);

        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);

        $amqpChannel->expects(self::never())
            ->method('confirm_select');

        self::assertSame($amqpChannel, $connection->channel());
        self::assertSame($amqpChannel, $connection->channel());
    }

    public function testGet(): void
    {
        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $this->connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testPublish(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $body    = 'test body';
        $headers = ['header1' => 'value1', 'header2' => 'value2'];

        $amqpMessage = new AMQPMessage(
            $body,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($headers),
            ],
        );

        $amqpChannel->expects(self::once())
            ->method('basic_publish')
            ->with(
                body: $amqpMessage,
                exchange: 'exchange_name',
                routing_key: '',
            );

        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $connection->publish(body: 'test body', headers: $headers);
    }

    public function testPublishWithDelayDeclaresTransientDelayQueueByDefault(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->with(
                queue: 'delay_exchange_name__5000_delay',
                passive: false,
                durable: false,
                exclusive: false,
                auto_delete: true,
                nowait: false,
                arguments: new AMQPTable([
                    'x-message-ttl' => 5000,
                    'x-expires' => 15000,
                    'x-dead-letter-exchange' => 'exchange_name',
                    'x-dead-letter-routing-key' => '',
                ]),
            );

        $connection->publish(body: 'test body', delayInMs: 5000);
    }

    public function testPublishWithDelayQueueDurableDeclaresDurableDelayQueue(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
            delay: new DelayConfig(queueDurable: true),
        ));

        $amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->with(
                queue: 'delay_exchange_name__5000_delay',
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: true,
                nowait: false,
                arguments: new AMQPTable([
                    'x-message-ttl' => 5000,
                    'x-expires' => 15000,
                    'x-dead-letter-exchange' => 'exchange_name',
                    'x-dead-letter-routing-key' => '',
                ]),
            );

        $connection->publish(body: 'test body', delayInMs: 5000);
    }

    public function testPublishWithConfirmDisabled(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            exchange: new ExchangeConfig(name: 'exchange_name'),
            confirmEnabled: false,
        ));

        $body = 'test body';

        $amqpMessage = new AMQPMessage(
            $body,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(),
            ],
        );

        $amqpChannel->expects(self::once())
            ->method('basic_publish')
            ->with(
                body: $amqpMessage,
                exchange: 'exchange_name',
                routing_key: '',
            );

        $amqpChannel->expects(self::never())
            ->method('wait_for_pending_acks');

        $connection->publish(body: 'test body');
    }

    public function testPublishWithBatchSizeGreaterThanOne(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $body1 = 'test body 1';
        $body2 = 'test body 2';

        $amqpMessage1 = new AMQPMessage(
            $body1,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(),
            ],
        );

        $amqpMessage2 = new AMQPMessage(
            $body2,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(),
            ],
        );

        $amqpChannel->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel->expects(self::once())
            ->method('publish_batch');

        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $connection->publish(body: $body1, batchSize: 2);
        $connection->publish(body: $body2, batchSize: 2);
    }

    public function testPublishWithBatchSizeGreaterThanOneAndRetryAttemptDoesNotBatchPublish(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $body = 'test body';

        $amqpMessage = new AMQPMessage(
            $body,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(),
            ],
        );

        $amqpEnvelope = new AmqpEnvelope($amqpMessage);

        $amqpStamp = AmqpStamp::createFromAMQPEnvelope(
            amqpEnvelope: $amqpEnvelope,
            retryRoutingKey: 'test_retry_routing_key',
        );

        $amqpChannel->expects(self::once())
            ->method('basic_publish');

        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $connection->publish(body: $body, batchSize: 2, amqpStamp: $amqpStamp);
    }

    public function testFlush(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $amqpChannel->expects(self::once())
            ->method('publish_batch');

        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $connection->flush();
    }

    public function testFlushWithConfirmDisabled(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(
            new ConnectionConfig(confirmEnabled: false),
        );

        $amqpChannel->expects(self::once())
            ->method('publish_batch');

        $amqpChannel->expects(self::never())
            ->method('wait_for_pending_acks');

        $connection->flush();
    }

    public function testCountMessagesInQueues(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->willReturn(['queue_name', 2]);

        self::assertSame(2, $connection->countMessagesInQueues());
    }

    public function testGetQueueNames(): void
    {
        self::assertSame(['queue_name'], $this->connection->getQueueNames());
    }

    public function testRetryWithReconnectSuccess(): void
    {
        $count = 0;

        $check = $this->connection->retryWithReconnect(static function () use (&$count) {
            $count++;

            if ($count < 3) {
                throw new AMQPConnectionClosedException();
            }

            return 'test';
        }, waitTime: 0)->run();

        self::assertSame(3, $count);
        self::assertSame('test', $check);
    }

    public function testRetryWithReconnectFailure(): void
    {
        self::expectException(TransportException::class);
        self::expectExceptionMessage('test');

        $this->connection->retryWithReconnect(static function (): void {
            throw new AMQPConnectionClosedException('test');
        }, waitTime: 0)->run();
    }

    public function testRetrySuccess(): void
    {
        $count = 0;

        $check = $this->connection->retry(static function () use (&$count) {
            $count++;

            if ($count < 3) {
                throw new AMQPConnectionClosedException();
            }

            return 'test';
        }, waitTime: 0)->run();

        self::assertSame(3, $count);
        self::assertSame('test', $check);
    }

    public function testRetryFailure(): void
    {
        self::expectException(TransportException::class);
        self::expectExceptionMessage('test');

        $this->connection->retry(static function (): void {
            throw new AMQPConnectionClosedException('test');
        }, waitTime: 0)->run();
    }

    public function testChannelInvalidatesCachedChannelWhenConnectionClosed(): void
    {
        [$connection, $amqpConnection, $amqpChannel1] = $this->createConnectionWithAllMocks();

        $amqpChannel2 = $this->createMock(AMQPChannel::class);

        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2);

        $amqpConnection->method('isConnected')
            ->willReturn(false);

        $amqpChannel1->expects(self::once())
            ->method('confirm_select');

        $amqpChannel2->expects(self::once())
            ->method('confirm_select');

        self::assertSame($amqpChannel1, $connection->channel());
        self::assertSame($amqpChannel2, $connection->channel());
    }

    public function testPublishWithDelayReconnectsOnStaleConnection(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2, $delayQueueName] = $this->createConnectionForDelayPublishReconnectTest();

        $amqpChannel1->expects(self::once())
            ->method('exchange_declare');

        $amqpChannel1->expects(self::once())
            ->method('queue_declare')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $this->expectSuccessfulDelayQueueSetupOnChannel($amqpChannel2, $delayQueueName);

        $this->runDelayPublishWithZeroWaitTime($connection);
    }

    public function testPublishWithDelayReconnectsOnStaleChannelClosedException(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2, $delayQueueName] = $this->createConnectionForDelayPublishReconnectTest();

        $amqpChannel1->expects(self::once())
            ->method('exchange_declare');

        $amqpChannel1->expects(self::once())
            ->method('queue_declare')
            ->willThrowException(new AMQPChannelClosedException('Channel connection is closed.'));

        $this->expectSuccessfulDelayQueueSetupOnChannel($amqpChannel2, $delayQueueName);

        $this->runDelayPublishWithZeroWaitTime($connection);
    }

    public function testPublishWithDelayReconnectsWhenExchangeDeclareFailsOnStaleConnection(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2, $delayQueueName] = $this->createConnectionForDelayPublishReconnectTest();

        $amqpChannel1->expects(self::once())
            ->method('exchange_declare')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $amqpChannel1->expects(self::never())
            ->method('queue_declare');

        $amqpChannel2->expects(self::once())
            ->method('exchange_declare');

        $this->expectSuccessfulDelayQueueSetupOnChannel($amqpChannel2, $delayQueueName);

        $this->runDelayPublishWithZeroWaitTime($connection);
    }

    public function testPublishWithDelayReconnectsWhenAutoSetupDelayDisabled(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2, $delayQueueName] = $this->createConnectionForDelayPublishReconnectTest(
            new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: true,
                confirmTimeout: 5.0,
                exchange: new ExchangeConfig(name: 'exchange_name'),
                delay: new DelayConfig(autoSetup: false),
                queues: [
                    'queue_name' => new QueueConfig(name: 'queue_name'),
                ],
            ),
        );

        $amqpChannel1->expects(self::never())
            ->method('exchange_declare');

        $amqpChannel1->expects(self::once())
            ->method('queue_declare')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $amqpChannel2->expects(self::never())
            ->method('exchange_declare');

        $this->expectSuccessfulDelayQueueSetupOnChannel($amqpChannel2, $delayQueueName);

        $this->runDelayPublishWithZeroWaitTime($connection);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->retryFactory = new RetryFactory();

        $this->connection = $this->createConnectionWithStubs();
    }
}

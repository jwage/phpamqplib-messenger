<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConsumer;
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
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;
use Symfony\Component\Messenger\Exception\TransportException;
use Traversable;

use function assert;
use function is_array;
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

    /**
     * @return array{
     *     0: Connection,
     *     1: AMQPStreamConnection&MockObject,
     *     2: AMQPChannel&MockObject,
     *     3: AMQPChannel&MockObject,
     * }
     */
    private function createConnectionForBatchFlushRetryTest(
        ConnectionConfig|null $connectionConfig = null,
        bool $requiresReconnect = false,
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
        if ($requiresReconnect) {
            $amqpConnection->expects(self::exactly(3))
                ->method('channel')
                ->willReturnOnConsecutiveCalls(
                    $amqpChannel1,
                    self::throwException(new AMQPConnectionClosedException('Connection is still closed')),
                    $amqpChannel2,
                );
            $amqpConnection->expects(self::once())
                ->method('reconnect');
        } else {
            $amqpConnection->expects(self::exactly(2))
                ->method('channel')
                ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2);
            $amqpConnection->expects(self::never())
                ->method('reconnect');
        }

        $amqpChannel1->method('confirm_select');
        $amqpChannel2->method('confirm_select');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        return [$connection, $amqpConnection, $amqpChannel1, $amqpChannel2];
    }

    /** @return list<array{0: AMQPMessage, 1: string, 2: string}> */
    private function getPendingBatchMessages(Connection $connection): array
    {
        $batchMessagesProperty = new ReflectionProperty(Connection::class, 'batchMessages');

        /** @var list<array{0: AMQPMessage, 1: string, 2: string}> $batchMessages */
        $batchMessages = $batchMessagesProperty->getValue($connection);

        return $batchMessages;
    }

    private function createPersistentAmqpMessage(string $body): AMQPMessage
    {
        return new AMQPMessage(
            $body,
            [
                'content_type' => 'text/plain',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(),
            ],
        );
    }

    private function runWithZeroRetryWaitTime(callable $run): void
    {
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $run();
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

    public function testCloseInvalidatesAConsumerWithoutCancellingItThroughTheBroker(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: ['queue_name' => new QueueConfig(name: 'queue_name')],
        );

        $factory        = $this->createMock(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->expects(self::once())
            ->method('create')
            ->willReturn($amqpConnection);

        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);
        // A broker-blocked connection still reports itself as connected. close() must not
        // wait for basic.cancel-ok before closing the connection that owns the consumer.
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::never())
            ->method('reconnect');
        $amqpConnection->expects(self::once())
            ->method('close');

        $amqpChannel->expects(self::once())
            ->method('basic_qos');
        $amqpChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag');
        $amqpChannel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPTimeoutException('poll timeout'));
        $amqpChannel->expects(self::never())
            ->method('basic_cancel');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        self::assertSame([], iterator_to_array($connection->consume('queue_name')));

        $consumer = (new ReflectionProperty(Connection::class, 'consumer'))->getValue($connection);
        assert($consumer instanceof AmqpConsumer);

        $consumerTagProperty = new ReflectionProperty(AmqpConsumer::class, 'consumerTag');
        self::assertSame('consumer-tag', $consumerTagProperty->getValue($consumer));

        $consumer->callback(new AMQPMessage('stale delivery'));

        $bufferProperty = new ReflectionProperty(AmqpConsumer::class, 'buffer');
        $buffer         = $bufferProperty->getValue($consumer);
        assert(is_array($buffer));
        self::assertCount(1, $buffer);

        $connection->close();

        self::assertNull($consumerTagProperty->getValue($consumer));
        self::assertSame([], $bufferProperty->getValue($consumer));
    }

    public function testCloseClearsConnectionSoNextChannelOpensFresh(): void
    {
        $factory         = $this->createMock(AmqpConnectionFactory::class);
        $amqpConnection1 = $this->createMock(AMQPStreamConnection::class);
        $amqpConnection2 = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel1    = $this->createStub(AMQPChannel::class);
        $amqpChannel2    = $this->createStub(AMQPChannel::class);

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($amqpConnection1, $amqpConnection2);

        $amqpConnection1->method('isConnected')->willReturn(true);
        $amqpConnection2->method('isConnected')->willReturn(true);
        $amqpConnection1->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel1);
        $amqpConnection2->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel2);
        $amqpConnection1->expects(self::once())
            ->method('close');

        $amqpChannel1->method('confirm_select');
        $amqpChannel2->method('confirm_select');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: new ConnectionConfig(confirmEnabled: true),
        );

        self::assertSame($amqpChannel1, $connection->channel());

        $connection->close();

        self::assertSame($amqpChannel2, $connection->channel());
    }

    public function testCloseRetainsPendingBatchMessagesSoALaterFlushStillPublishesThem(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(
            new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                exchange: new ExchangeConfig(name: 'exchange_name'),
            ),
        );

        $amqpMessage = $this->createPersistentAmqpMessage('pending body');

        // The batch buffer is owned by this class and is not tied to any one connection,
        // so closing must not throw away messages publish() has already accepted.
        $amqpChannel->expects(self::once())
            ->method('batch_basic_publish')
            ->with($amqpMessage, 'exchange_name');

        $amqpChannel->expects(self::once())
            ->method('publish_batch');

        $connection->publish(body: 'pending body', batchSize: 5);

        self::assertCount(1, $this->getPendingBatchMessages($connection));

        $connection->close();

        self::assertCount(1, $this->getPendingBatchMessages($connection));

        $connection->flush();

        self::assertSame([], $this->getPendingBatchMessages($connection));
    }

    public function testCloseRetainsPendingBatchMessagesWhenConnectionCloseFails(): void
    {
        [$connection, $amqpConnection] = $this->createConnectionWithConnectionMock(
            new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                exchange: new ExchangeConfig(name: 'exchange_name'),
            ),
        );

        $connection->channel();
        $connection->publish(body: 'pending body', batchSize: 5);

        self::assertCount(1, $this->getPendingBatchMessages($connection));

        $closeException = new AMQPConnectionClosedException('Connection close failed');

        $amqpConnection->expects(self::once())
            ->method('close')
            ->willThrowException($closeException);

        try {
            $connection->close();
            self::fail('Expected connection close to fail.');
        } catch (AMQPConnectionClosedException $exception) {
            self::assertSame($closeException, $exception);
        }

        // A failing close() still resets the connection state, but the pending batch
        // survives so the next flush() publishes it onto a fresh connection.
        self::assertCount(1, $this->getPendingBatchMessages($connection));

        $connection->flush();

        self::assertSame([], $this->getPendingBatchMessages($connection));
    }

    public function testConsumerResumesOnANewChannelAfterANonRetryableFlushFailure(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
            queues: ['queue_name' => new QueueConfig(name: 'queue_name')],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel1   = $this->createMock(AMQPChannel::class);
        $amqpChannel2   = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        // A blocked connection is still connected, so channel() only replaces the cached
        // channel because the failed flush discarded it -- not because of a disconnect.
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2);
        // AMQPConnectionBlockedException is not retryable, so nothing reconnects.
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $staleEnvelope = new AmqpEnvelope(new AMQPMessage('stale delivery'));

        $amqpChannel1->method('is_consuming')->willReturn(true);
        $amqpChannel1->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-1');
        $amqpChannel1->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));
        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionBlockedException('Connection blocked'));

        $amqpChannel2->method('is_consuming')->willReturn(true);
        $amqpChannel2->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        // 1. Register a consumer on channel1.
        self::assertSame([], iterator_to_array($connection->consume('queue_name')));

        $consumer = (new ReflectionProperty(Connection::class, 'consumer'))->getValue($connection);
        assert($consumer instanceof AmqpConsumer);

        // A delivery buffered on channel1, whose delivery tag dies with that channel.
        $bufferProperty = new ReflectionProperty(AmqpConsumer::class, 'buffer');
        $bufferProperty->setValue($consumer, [$staleEnvelope]);

        // 2. The batch flush fails with a non-retryable error, discarding channel1.
        $connection->publish(body: 'body 1', batchSize: 3);
        $connection->publish(body: 'body 2', batchSize: 3);

        try {
            $connection->flush();
            self::fail('Expected AMQPConnectionBlockedException was not thrown.');
        } catch (AMQPConnectionBlockedException $exception) {
            self::assertSame('Connection blocked', $exception->getMessage());
        }

        // 4. basic_consume is issued on channel2, so consumption resumes rather than
        //    silently polling a channel that has no consumer registered on it.
        $amqpChannel2->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-2');

        // 3. and 5. The next consume() re-registers and yields nothing, because the stale
        //    envelope buffered against the discarded channel was dropped with it.
        self::assertSame([], iterator_to_array($connection->consume('queue_name')));
        self::assertSame([], $bufferProperty->getValue($consumer));

        // Leave no live registration behind: __destruct() would otherwise call stop(),
        // which resolves a channel against a mock PHPUnit has already torn down.
        $consumer->invalidate();
    }

    public function testConsumerResumesWhenADeadConnectionReplacesTheCachedChannel(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: ['queue_name' => new QueueConfig(name: 'queue_name')],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel1   = $this->createMock(AMQPChannel::class);
        $amqpChannel2   = $this->createMock(AMQPChannel::class);
        $connected      = true;
        $channelCalls   = 0;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturnCallback(
            static function () use (&$connected): bool {
                return $connected;
            },
        );
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnCallback(
                static function () use (&$connected, &$channelCalls, $amqpChannel1, $amqpChannel2): AMQPChannel {
                    $channelCalls++;

                    if ($channelCalls === 1) {
                        return $amqpChannel1;
                    }

                    // php-amqplib's channel() calls connect() when disconnected, which
                    // marks the connection live again before returning the new channel.
                    $connected = true;

                    return $amqpChannel2;
                },
            );
        // php-amqplib can internally connect() when opening a channel after disconnect;
        // Connection must not skip re-registering the consumer on that replacement channel.
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $amqpChannel1->method('is_consuming')->willReturn(true);
        $amqpChannel1->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-1');
        $amqpChannel1->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));

        $amqpChannel2->method('is_consuming')->willReturn(true);
        $amqpChannel2->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));
        $amqpChannel2->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-2');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        self::assertSame([], iterator_to_array($connection->consume('queue_name')));

        $connected = false;

        self::assertSame([], iterator_to_array($connection->consume('queue_name')));

        $consumer = (new ReflectionProperty(Connection::class, 'consumer'))->getValue($connection);
        assert($consumer instanceof AmqpConsumer);
        $consumer->invalidate();
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

    public function testPublishPreservesThePublishExceptionWhenRollbackAlsoFails(): void
    {
        [$connection, $amqpConnection, $amqpChannel] = $this->createConnectionWithAllMocks(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            transactionsEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $publishException  = new AMQPConnectionClosedException('Publish failed');
        $rollbackException = new AMQPConnectionClosedException('Rollback failed');

        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);

        $amqpChannel->expects(self::once())
            ->method('tx_select');
        $amqpChannel->expects(self::once())
            ->method('basic_publish')
            ->willThrowException($publishException);
        $amqpChannel->expects(self::once())
            ->method('tx_rollback')
            ->willThrowException($rollbackException);
        $amqpChannel->expects(self::never())
            ->method('tx_commit');

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->publish(body: 'test body');
                self::fail('Expected publishing to fail.');
            } catch (TransportException $exception) {
                self::assertSame($publishException, $exception->getPrevious());
            }
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testPublishPreservesTheCommitExceptionWhenRollbackAlsoFails(): void
    {
        [$connection, $amqpConnection, $amqpChannel] = $this->createConnectionWithAllMocks(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            transactionsEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $commitException   = new AMQPConnectionClosedException('Commit failed');
        $rollbackException = new AMQPConnectionClosedException('Rollback failed');

        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);

        $amqpChannel->expects(self::once())
            ->method('tx_select');
        $amqpChannel->expects(self::once())
            ->method('basic_publish');
        $amqpChannel->expects(self::once())
            ->method('tx_commit')
            ->willThrowException($commitException);
        $amqpChannel->expects(self::once())
            ->method('tx_rollback')
            ->willThrowException($rollbackException);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->publish(body: 'test body');
                self::fail('Expected publishing to fail.');
            } catch (TransportException $exception) {
                self::assertSame($commitException, $exception->getPrevious());
            }
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testPublishOpensANewChannelAfterTransactionalPublishFails(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            transactionsEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
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

        $amqpChannel1->expects(self::once())
            ->method('tx_select');
        $amqpChannel1->expects(self::once())
            ->method('basic_publish')
            ->willThrowException(new AMQPConnectionClosedException('Publish failed'));
        $amqpChannel1->expects(self::once())
            ->method('tx_rollback')
            ->willThrowException(new AMQPConnectionClosedException('Rollback failed'));
        $amqpChannel1->expects(self::never())
            ->method('tx_commit');

        $amqpChannel2->expects(self::once())
            ->method('tx_select');
        $amqpChannel2->expects(self::once())
            ->method('basic_publish');
        $amqpChannel2->expects(self::once())
            ->method('tx_commit');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->publish(body: 'first body');
                self::fail('Expected the first publish to fail.');
            } catch (TransportException) {
            }

            $connection->publish(body: 'second body');
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
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

    public function testFlushWithEmptyBatchIsNoOp(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $amqpChannel->expects(self::never())
            ->method('batch_basic_publish');

        $amqpChannel->expects(self::never())
            ->method('publish_batch');

        $amqpChannel->expects(self::never())
            ->method('wait_for_pending_acks');

        $connection->flush();
    }

    public function testFlushWithConfirmDisabled(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(
            new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                exchange: new ExchangeConfig(name: 'exchange_name'),
            ),
        );

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
            ->method('batch_basic_publish')
            ->with($amqpMessage, 'exchange_name');

        $amqpChannel->expects(self::once())
            ->method('publish_batch');

        $amqpChannel->expects(self::never())
            ->method('wait_for_pending_acks');

        $connection->publish(body: $body, batchSize: 2);
        $connection->flush();
    }

    public function testFlushRepublishesBatchAfterConnectionClosed(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest(
            requiresReconnect: true,
        );

        $body1 = 'test body 1';
        $body2 = 'test body 2';

        $amqpMessage1 = $this->createPersistentAmqpMessage($body1);
        $amqpMessage2 = $this->createPersistentAmqpMessage($body2);

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $amqpChannel1->expects(self::never())
            ->method('wait_for_pending_acks');

        $amqpChannel2->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel2->expects(self::once())
            ->method('publish_batch');

        $amqpChannel2->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $this->runWithZeroRetryWaitTime(static function () use ($connection, $body1, $body2): void {
            $connection->publish(body: $body1, batchSize: 2);
            $connection->publish(body: $body2, batchSize: 2);
        });

        self::assertSame([], $this->getPendingBatchMessages($connection));
    }

    public function testFlushRepublishesBatchAfterChannelClosed(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest();

        $body1 = 'test body 1';
        $body2 = 'test body 2';

        $amqpMessage1 = $this->createPersistentAmqpMessage($body1);
        $amqpMessage2 = $this->createPersistentAmqpMessage($body2);

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');

        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPChannelClosedException('Channel connection is closed.'));

        $amqpChannel2->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel2->expects(self::once())
            ->method('publish_batch');

        $amqpChannel2->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $this->runWithZeroRetryWaitTime(static function () use ($connection, $body1, $body2): void {
            $connection->publish(body: $body1, batchSize: 2);
            $connection->publish(body: $body2, batchSize: 2);
        });
    }

    public function testFlushRepublishesPartialBatchAfterConnectionClosed(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest(
            requiresReconnect: true,
        );

        $body1 = 'partial body 1';
        $body2 = 'partial body 2';

        $amqpMessage1 = $this->createPersistentAmqpMessage($body1);
        $amqpMessage2 = $this->createPersistentAmqpMessage($body2);

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $amqpChannel2->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel2->expects(self::once())
            ->method('publish_batch');

        $amqpChannel2->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $this->runWithZeroRetryWaitTime(function () use ($connection, $body1, $body2): void {
            $connection->publish(body: $body1, batchSize: 5);
            $connection->publish(body: $body2, batchSize: 5);

            self::assertCount(2, $this->getPendingBatchMessages($connection));

            $connection->flush();
        });

        self::assertSame([], $this->getPendingBatchMessages($connection));
    }

    public function testFlushRepublishesBatchWithoutReconnectWhenWaitForPendingAcksTimesOut(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest();

        $body1 = 'confirm body 1';
        $body2 = 'confirm body 2';

        $amqpMessage1 = $this->createPersistentAmqpMessage($body1);
        $amqpMessage2 = $this->createPersistentAmqpMessage($body2);

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');

        $amqpChannel1->expects(self::once())
            ->method('publish_batch');

        $amqpChannel1->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willThrowException(new AMQPTimeoutException('Confirm timeout'));

        $amqpChannel2->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel2->expects(self::once())
            ->method('publish_batch');

        $amqpChannel2->expects(self::once())
            ->method('wait_for_pending_acks')
            ->with(timeout: 5);

        $this->runWithZeroRetryWaitTime(static function () use ($connection, $body1, $body2): void {
            $connection->publish(body: $body1, batchSize: 2);
            $connection->publish(body: $body2, batchSize: 2);
        });
    }

    public function testFlushWithTransactionsRepublishesBatchAfterConnectionClosed(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest(
            new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                transactionsEnabled: true,
                exchange: new ExchangeConfig(name: 'exchange_name'),
                queues: [
                    'queue_name' => new QueueConfig(name: 'queue_name'),
                ],
            ),
            requiresReconnect: true,
        );

        $body1 = 'tx body 1';
        $body2 = 'tx body 2';

        $amqpChannel1->expects(self::once())
            ->method('tx_select');

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');

        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $amqpChannel1->expects(self::once())
            ->method('tx_rollback');

        $amqpChannel1->expects(self::never())
            ->method('tx_commit');

        $amqpChannel2->expects(self::once())
            ->method('tx_select');

        $amqpChannel2->expects(self::exactly(2))
            ->method('batch_basic_publish');

        $amqpChannel2->expects(self::once())
            ->method('publish_batch');

        $amqpChannel2->expects(self::once())
            ->method('tx_commit');

        $amqpChannel2->expects(self::never())
            ->method('wait_for_pending_acks');

        $this->runWithZeroRetryWaitTime(static function () use ($connection, $body1, $body2): void {
            $connection->publish(body: $body1, batchSize: 2);
            $connection->publish(body: $body2, batchSize: 2);
        });
    }

    public function testFlushPreservesThePublishExceptionWhenRollbackAlsoFails(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            transactionsEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $publishException  = new AMQPConnectionClosedException('Batch publish failed');
        $rollbackException = new AMQPConnectionClosedException('Batch rollback failed');

        $amqpChannel->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel->expects(self::once())
            ->method('tx_select');
        $amqpChannel->expects(self::once())
            ->method('publish_batch')
            ->willThrowException($publishException);
        $amqpChannel->expects(self::once())
            ->method('tx_rollback')
            ->willThrowException($rollbackException);
        $amqpChannel->expects(self::never())
            ->method('tx_commit');

        $connection->publish(body: 'body 1', batchSize: 3);
        $connection->publish(body: 'body 2', batchSize: 3);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected batch publishing to fail.');
            } catch (TransportException $exception) {
                self::assertSame($publishException, $exception->getPrevious());
            }
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertCount(2, $this->getPendingBatchMessages($connection));
    }

    public function testFlushPreservesTheCommitExceptionWhenRollbackAlsoFails(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            transactionsEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $commitException   = new AMQPConnectionClosedException('Batch commit failed');
        $rollbackException = new AMQPConnectionClosedException('Batch rollback failed');

        $amqpChannel->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel->expects(self::once())
            ->method('tx_select');
        $amqpChannel->expects(self::once())
            ->method('publish_batch');
        $amqpChannel->expects(self::once())
            ->method('tx_commit')
            ->willThrowException($commitException);
        $amqpChannel->expects(self::once())
            ->method('tx_rollback')
            ->willThrowException($rollbackException);

        $connection->publish(body: 'body 1', batchSize: 3);
        $connection->publish(body: 'body 2', batchSize: 3);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected batch publishing to fail.');
            } catch (TransportException $exception) {
                self::assertSame($commitException, $exception->getPrevious());
            }
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertCount(2, $this->getPendingBatchMessages($connection));
    }

    public function testFlushPreservesBatchWhenRetriesAreExhausted(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpChannel->method('confirm_select');

        $amqpChannel->expects(self::atLeastOnce())
            ->method('batch_basic_publish');

        $amqpChannel->expects(self::atLeastOnce())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $body1 = 'retained body 1';
        $body2 = 'retained body 2';

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->publish(body: $body1, batchSize: 2);

            try {
                $connection->publish(body: $body2, batchSize: 2);
                self::fail('Expected TransportException was not thrown.');
            } catch (TransportException $exception) {
                self::assertSame('Broken pipe or closed connection', $exception->getMessage());
            }

            $pendingBatchMessages = $this->getPendingBatchMessages($connection);

            self::assertCount(2, $pendingBatchMessages);
            self::assertSame($body1, $pendingBatchMessages[0][0]->getBody());
            self::assertSame($body2, $pendingBatchMessages[1][0]->getBody());
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testFlushAfterExhaustedRetriesDoesNotDuplicateBatchOnLaterFlush(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
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
        // Connection stays up (e.g. blocked); retries=0 means no reconnect, but each flush
        // attempt must still open a fresh channel so leftover batch buffers are not appended to.
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2);
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $body1 = 'blocked body 1';
        $body2 = 'blocked body 2';

        $amqpMessage1 = $this->createPersistentAmqpMessage($body1);
        $amqpMessage2 = $this->createPersistentAmqpMessage($body2);

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionBlockedException('Connection blocked'));

        $amqpChannel2->expects(self::exactly(2))
            ->method('batch_basic_publish')
            ->with(...self::withConsecutive(
                [$amqpMessage1, 'exchange_name'],
                [$amqpMessage2, 'exchange_name'],
            ));

        $amqpChannel2->expects(self::once())
            ->method('publish_batch');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->publish(body: $body1, batchSize: 3);
            $connection->publish(body: $body2, batchSize: 3);

            try {
                $connection->flush();
                self::fail('Expected an AMQP flush failure was not thrown.');
            } catch (AMQPConnectionBlockedException $exception) {
                // RetryFactory does not treat blocked connections as retryable, so the
                // raw exception surfaces; the owned batch must still be retained.
                self::assertSame('Connection blocked', $exception->getMessage());
            }

            self::assertCount(2, $this->getPendingBatchMessages($connection));

            $connection->flush();

            self::assertSame([], $this->getPendingBatchMessages($connection));
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testAutoFlushStillFiresAfterAFailedFlushRetainedTheBatch(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(
            new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                exchange: new ExchangeConfig(name: 'exchange_name'),
            ),
        );

        $publishBatchCalls = 0;

        // First flush fails, so its two messages stay buffered. The second must still be
        // attempted even though the buffer has grown past the batch size.
        $amqpChannel->expects(self::exactly(2))
            ->method('publish_batch')
            ->willReturnCallback(
                static function () use (&$publishBatchCalls): void {
                    $publishBatchCalls++;

                    if ($publishBatchCalls === 1) {
                        throw new AMQPConnectionClosedException('Broken pipe or closed connection');
                    }
                },
            );

        // Two messages on the failed attempt, then all three on the successful one.
        $amqpChannel->expects(self::exactly(5))
            ->method('batch_basic_publish');

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->publish(body: 'body 1', batchSize: 2);

            try {
                $connection->publish(body: 'body 2', batchSize: 2);
                self::fail('Expected TransportException was not thrown.');
            } catch (TransportException) {
            }

            self::assertCount(2, $this->getPendingBatchMessages($connection));

            // The buffer is now at 3, past the batch size of 2. With an == threshold this
            // publish would buffer silently and never flush again.
            $connection->publish(body: 'body 3', batchSize: 2);

            self::assertSame([], $this->getPendingBatchMessages($connection));
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testPublishDoesNotTouchChannelUntilBatchFlush(): void
    {
        [$connection, $amqpConnection, $amqpChannel] = $this->createConnectionWithAllMocks(
            new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                exchange: new ExchangeConfig(name: 'exchange_name'),
            ),
        );

        $amqpConnection->expects(self::never())
            ->method('channel');

        $amqpChannel->expects(self::never())
            ->method('batch_basic_publish');

        $amqpChannel->expects(self::never())
            ->method('publish_batch');

        $connection->publish(body: 'buffered only', batchSize: 3);

        self::assertCount(1, $this->getPendingBatchMessages($connection));
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

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Debug;
use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\CollectingLogger;
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
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use Jwage\PhpAmqpLibMessengerBundle\Transport\PublisherNack;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use SplQueue;
use stdClass;
use Symfony\Component\Messenger\Exception\TransportException;
use Traversable;

use function assert;
use function fclose;
use function fwrite;
use function hrtime;
use function is_array;
use function iterator_to_array;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_pair;

use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

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
        bool $usesSingleChannel = false,
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
        if ($usesSingleChannel) {
            $amqpConnection->expects(self::once())
                ->method('channel')
                ->willReturn($amqpChannel1);
            $amqpConnection->expects(self::never())
                ->method('reconnect');
        } elseif ($requiresReconnect) {
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

    private function assertDirectPublishRetriesRecoverableFailure(
        AMQPChannelClosedException|AMQPConnectionClosedException|AMQPIOException $firstAttemptException,
        bool $expectReconnect,
    ): void {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel1   = $this->createMock(AMQPChannel::class);
        $amqpChannel2   = $this->createMock(AMQPChannel::class);
        $connectionDead = false;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')
            ->willReturnCallback(static function () use (&$connectionDead): bool {
                return $connectionDead === false;
            });
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2);

        if ($expectReconnect) {
            $amqpConnection->expects(self::once())
                ->method('reconnect')
                ->willReturnCallback(static function () use (&$connectionDead): void {
                    $connectionDead = false;
                });
        } else {
            $amqpConnection->expects(self::never())
                ->method('reconnect');
        }

        $amqpChannel1->method('confirm_select');
        $amqpChannel1->expects(self::once())
            ->method('basic_publish')
            ->willReturnCallback(static function () use (&$connectionDead, $expectReconnect, $firstAttemptException): void {
                if ($expectReconnect) {
                    $connectionDead = true;
                }

                throw $firstAttemptException;
            });
        $amqpChannel1->expects(self::never())
            ->method('wait_for_pending_acks');

        $amqpChannel2->method('confirm_select');
        $amqpChannel2->expects(self::once())
            ->method('basic_publish');
        $amqpChannel2->expects(self::once())
            ->method('wait_for_pending_acks');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $this->runWithZeroRetryWaitTime(static function () use ($connection): void {
            $connection->publish(body: 'retried body');
        });
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

    private function getConsumer(Connection $connection, string $queueName = 'queue_name'): AmqpConsumer
    {
        $consumers = (new ReflectionProperty(Connection::class, 'consumers'))->getValue($connection);
        assert(is_array($consumers));

        $consumer = $consumers[$queueName] ?? null;
        assert($consumer instanceof AmqpConsumer);

        return $consumer;
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

        $amqpChannel->method('is_open')->willReturn(true);
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

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertSame([], iterator_to_array($envelopes));

        $consumer = $this->getConsumer($connection);

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

    public function testDestructorInvalidatesConsumers(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
        ));

        $consumer = $this->createMock(AmqpConsumer::class);
        $consumer->expects(self::once())
            ->method('invalidate');

        (new ReflectionProperty(Connection::class, 'consumers'))->setValue($connection, ['queue_name' => $consumer]);

        $connection->__destruct();
    }

    public function testHasBufferedDeliveriesIsFalseWhenNoConsumersExist(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
        ));

        self::assertFalse($connection->hasBufferedDeliveries());
    }

    public function testHasBufferedDeliveriesChecksEveryConsumer(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
        ));

        $empty = $this->createStub(AmqpConsumer::class);
        $empty->method('hasBufferedEnvelopes')->willReturn(false);

        $full = $this->createStub(AmqpConsumer::class);
        $full->method('hasBufferedEnvelopes')->willReturn(true);

        $consumers = new ReflectionProperty(Connection::class, 'consumers');

        $consumers->setValue($connection, ['a' => $empty, 'b' => $full]);
        self::assertTrue($connection->hasBufferedDeliveries());

        $consumers->setValue($connection, ['a' => $full, 'b' => $empty]);
        self::assertTrue($connection->hasBufferedDeliveries());

        $consumers->setValue($connection, ['a' => $empty, 'b' => $empty]);
        self::assertFalse($connection->hasBufferedDeliveries());
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

        $amqpConnection->method('isConnected')->willReturn(true);

        try {
            $connection->close();
            self::fail('Expected connection close to fail.');
        } catch (AMQPConnectionClosedException $exception) {
            self::assertSame($closeException, $exception);
        }

        self::assertFalse($connection->isConnected());

        // A failing close() still resets the connection state, but the pending batch
        // survives so the next flush() publishes it onto a fresh connection.
        self::assertCount(1, $this->getPendingBatchMessages($connection));

        $connection->flush();

        self::assertSame([], $this->getPendingBatchMessages($connection));
    }

    public function testPublisherFailureDoesNotInvalidateConsumerOnSharedConnection(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
            queues: ['queue_name' => new QueueConfig(name: 'queue_name')],
        );

        $factory          = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection   = $this->createMock(AMQPStreamConnection::class);
        $consumerChannel  = $this->createMock(AMQPChannel::class);
        $publisherChannel = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($consumerChannel, $publisherChannel);
        // AMQPConnectionBlockedException is not retryable, so nothing reconnects.
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $bufferedEnvelope = new AmqpEnvelope(new AMQPMessage('buffered delivery'));

        $consumerChannel->method('is_open')->willReturn(true);
        $consumerChannel->method('is_consuming')->willReturn(true);
        $consumerChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-1');
        $consumerChannel->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));
        $consumerChannel->expects(self::never())
            ->method('closeIfDisconnected');

        $publisherChannel->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $publisherChannel->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionBlockedException('Connection blocked'));
        $publisherChannel->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(false);

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        // Register a consumer on its dedicated channel.
        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertSame([], iterator_to_array($envelopes));

        $consumer = $this->getConsumer($connection);

        // Simulate a delivery already received on the consumer channel.
        $bufferProperty = new ReflectionProperty(AmqpConsumer::class, 'buffer');
        $bufferProperty->setValue($consumer, [$bufferedEnvelope]);

        // A non-retryable publisher failure retires only the publisher channel.
        $connection->publish(body: 'body 1', batchSize: 3);
        $connection->publish(body: 'body 2', batchSize: 3);

        try {
            $connection->flush();
            self::fail('Expected AMQPConnectionBlockedException was not thrown.');
        } catch (AMQPConnectionBlockedException $exception) {
            self::assertSame('Connection blocked', $exception->getMessage());
        }

        // The original consumer remains registered and its delivery remains usable.
        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertSame([$bufferedEnvelope], iterator_to_array($envelopes));
        self::assertSame([], $bufferProperty->getValue($consumer));

        // Leave no live registration behind: __destruct() would otherwise call stop(),
        // which resolves a channel against a mock PHPUnit has already torn down.
        $consumer->invalidate();
    }

    public function testRetryablePublisherFailureDoesNotInvalidateAnOutstandingConsumerDelivery(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
            queues: ['queue_name' => new QueueConfig(name: 'queue_name')],
        );

        $factory           = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection    = $this->createMock(AMQPStreamConnection::class);
        $consumerChannel   = $this->createMock(AMQPChannel::class);
        $publisherChannel1 = $this->createMock(AMQPChannel::class);
        $publisherChannel2 = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::exactly(3))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($consumerChannel, $publisherChannel1, $publisherChannel2);
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $consumerChannel->method('is_open')->willReturn(true);
        $consumerChannel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);
        $consumerChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag');
        $consumerChannel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPTimeoutException('poll timeout'));
        $consumerChannel->expects(self::once())
            ->method('basic_ack')
            ->with(1, false);
        $consumerChannel->expects(self::never())
            ->method('closeIfDisconnected');

        $publisherChannel1->expects(self::once())
            ->method('basic_publish')
            ->willThrowException(new AMQPChannelClosedException('Publisher channel closed'));
        $publisherChannel1->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(false);
        $publisherChannel1->expects(self::once())
            ->method('close');

        $publisherChannel2->expects(self::once())
            ->method('basic_publish');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        self::assertSame([], iterator_to_array($envelopes));

        $consumer = $this->getConsumer($connection);

        $message = new AMQPMessage('in-flight delivery');
        $message->setChannel($consumerChannel);
        $message->setDeliveryInfo(1, false, 'exchange_name', 'queue_name');
        $consumer->callback($message);

        $this->runWithZeroRetryWaitTime(
            static function () use ($connection): void {
                $connection->publish(body: 'publisher retry');
            },
        );

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        $received  = iterator_to_array($envelopes);

        self::assertCount(1, $received);
        $received[0]->ack();

        $consumer->invalidate();
    }

    public function testBlockedPublisherProcessesAnUnblockFrameWithoutInvalidatingConsumerDelivery(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
            queues: ['queue_name' => new QueueConfig(name: 'queue_name')],
        );

        $factory          = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection   = $this->createMock(AMQPStreamConnection::class);
        $consumerChannel  = $this->createMock(AMQPChannel::class);
        $publisherChannel = $this->createMock(AMQPChannel::class);
        $blocked          = false;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->method('isBlocked')->willReturnCallback(
            static function () use (&$blocked): bool {
                return $blocked;
            },
        );
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($consumerChannel, $publisherChannel);
        $amqpConnection->expects(self::once())
            ->method('wait')
            ->with(null, true)
            ->willReturnCallback(
                static function () use (&$blocked): void {
                    // Model dispatching a real connection.unblocked frame: the socket
                    // read, rather than the test itself, clears php-amqplib's flag.
                    $blocked = false;
                },
            );
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $consumerChannel->method('is_open')->willReturn(true);
        $consumerChannel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);
        $consumerChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag');
        $consumerChannel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPTimeoutException('poll timeout'));
        $consumerChannel->expects(self::once())
            ->method('basic_ack')
            ->with(1, false);
        $consumerChannel->expects(self::never())
            ->method('closeIfDisconnected');

        $publisherChannel->expects(self::once())
            ->method('basic_publish');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        self::assertSame([], iterator_to_array($envelopes));

        $consumer = $this->getConsumer($connection);

        $message = new AMQPMessage('in-flight delivery');
        $message->setChannel($consumerChannel);
        $message->setDeliveryInfo(1, false, 'exchange_name', 'queue_name');
        $consumer->callback($message);

        $blocked = true;

        $connection->publish(body: 'publish after recovery');

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        $received  = iterator_to_array($envelopes);

        self::assertCount(1, $received);
        $received[0]->ack();

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

        $amqpChannel1->method('is_open')->willReturn(true);
        $amqpChannel1->method('is_consuming')->willReturn(true);
        $amqpChannel1->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-1');
        $amqpChannel1->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));
        $amqpChannel1->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(true);

        $amqpChannel2->method('is_open')->willReturn(true);
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

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertSame([], iterator_to_array($envelopes));

        $connected = false;

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertSame([], iterator_to_array($envelopes));

        $consumer = $this->getConsumer($connection);
        $consumer->invalidate();
    }

    public function testConsumerResumesWhenItsChannelClosesOnALiveConnection(): void
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
        $channel1Open   = true;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2);
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $amqpChannel1->method('is_open')->willReturnCallback(
            static function () use (&$channel1Open): bool {
                return $channel1Open;
            },
        );
        $amqpChannel1->method('is_consuming')->willReturn(true);
        $amqpChannel1->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-1');
        $amqpChannel1->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));

        $amqpChannel2->method('is_open')->willReturn(true);
        $amqpChannel2->method('is_consuming')->willReturn(true);
        $amqpChannel2->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-2');
        $amqpChannel2->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        self::assertSame([], iterator_to_array($envelopes));

        $consumer = $this->getConsumer($connection);
        $consumer->callback(new AMQPMessage('stale delivery'));

        $channel1Open = false;

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        self::assertSame([], iterator_to_array($envelopes));

        $bufferProperty = new ReflectionProperty(AmqpConsumer::class, 'buffer');
        self::assertSame([], $bufferProperty->getValue($consumer));

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

    public function testSetupBindsWithAnEmptyRoutingKeyWhenTheQueueHasNoBindings(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            exchange: new ExchangeConfig(name: 'exchange_name'),
            queues: ['queue_name' => new QueueConfig(name: 'queue_name')],
        ));

        $amqpChannel->expects(self::once())
            ->method('queue_bind')
            ->with(
                queue: 'queue_name',
                exchange: 'exchange_name',
                routing_key: '',
                nowait: false,
                arguments: new AMQPTable([]),
            );

        $connection->setup();
    }

    public function testConsumeSetsUpTopologyWhenAutoSetupIsEnabled(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $amqpChannel->expects(self::once())
            ->method('exchange_declare')
            ->with(
                'exchange_name',
                'fanout',
                false,
                true,
                false,
                false,
                false,
                new AMQPTable([]),
            );
        $amqpChannel->expects(self::once())
            ->method('queue_declare');
        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag');
        $amqpChannel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPTimeoutException('poll timeout'));

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        iterator_to_array($envelopes);
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

    public function testKeepaliveDoesNothingWhenTheConnectionHasNotBeenOpened(): void
    {
        [$connection, $amqpConnection] = $this->createConnectionWithConnectionMock();

        $amqpConnection->expects(self::never())->method('checkHeartBeat');

        $connection->keepalive();
    }

    public function testKeepaliveSendsAHeartbeatOnTheOpenConnection(): void
    {
        [$connection, $amqpConnection] = $this->createConnectionWithConnectionMock();

        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::once())->method('checkHeartBeat');

        $connection->channel();
        $connection->keepalive();
    }

    public function testKeepaliveWrapsAmqpExceptions(): void
    {
        [$connection, $amqpConnection] = $this->createConnectionWithConnectionMock();

        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::once())
            ->method('checkHeartBeat')
            ->willThrowException(new AMQPConnectionClosedException('connection closed'));

        $connection->channel();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('connection closed');
        $this->expectExceptionCode(0);

        $connection->keepalive();
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

    public function testChannelOpensWhenTheBrokerIsBlockedAndNoChannelsAreRetired(): void
    {
        [$connection, $amqpConnection, $amqpChannel] = $this->createConnectionWithAllMocks(
            new ConnectionConfig(confirmEnabled: false),
        );

        $amqpChannel2 = $this->createMock(AMQPChannel::class);
        $blocked      = false;

        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->method('isBlocked')->willReturnCallback(
            static function () use (&$blocked): bool {
                return $blocked;
            },
        );
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel, $amqpChannel2);
        $amqpChannel->expects(self::never())
            ->method('confirm_select');
        $amqpChannel2->expects(self::never())
            ->method('confirm_select');

        $connection->channel();

        $blocked = true;
        (new ReflectionProperty(Connection::class, 'channel'))->setValue($connection, null);

        self::assertSame($amqpChannel2, $connection->channel());
    }

    public function testChannelForgetsAPublisherChannelWhenTheConnectionIsDead(): void
    {
        [$connection, $amqpConnection, $amqpChannel] = $this->createConnectionWithAllMocks(
            new ConnectionConfig(confirmEnabled: false),
        );

        $amqpChannel2 = $this->createMock(AMQPChannel::class);
        $connected    = true;

        $amqpConnection->method('isConnected')->willReturnCallback(
            static function () use (&$connected): bool {
                return $connected;
            },
        );
        $amqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel, $amqpChannel2);
        $amqpChannel->expects(self::never())
            ->method('confirm_select');
        $amqpChannel2->expects(self::never())
            ->method('confirm_select');
        $amqpChannel->expects(self::once())
            ->method('closeIfDisconnected');

        $connection->channel();
        $connected = false;
        $connection->channel();
    }

    public function testGet(): void
    {
        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $this->connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumeAppliesFetchSizeWhenGreaterThanPrefetchCount(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(
                    name: 'queue_name',
                    prefetchCount: 1,
                ),
            ],
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
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::once())
            ->method('close');

        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 50,
                a_global: false,
            );
        $amqpChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag');
        $amqpChannel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPTimeoutException('poll timeout'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name', 50);

        self::assertSame([], iterator_to_array($envelopes));

        $connection->close();
    }

    public function testConsumeRegistersASeparateConsumerPerQueue(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
                'queue_name2' => new QueueConfig(name: 'queue_name2'),
            ],
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
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::once())
            ->method('close');

        $consumedQueues = [];

        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->method('is_consuming')->willReturn(true);
        $amqpChannel->method('wait')->willThrowException(new AMQPTimeoutException('poll timeout'));
        $amqpChannel->expects(self::never())
            ->method('basic_cancel');
        $amqpChannel->expects(self::exactly(2))
            ->method('basic_consume')
            ->willReturnCallback(
                static function (string $queue, mixed ...$_args) use (&$consumedQueues): string {
                    $consumedQueues[] = $queue;

                    return $queue === 'queue_name' ? 'consumer-tag-1' : 'consumer-tag-2';
                },
            );

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        self::assertSame([], iterator_to_array($envelopes));

        /** @var Traversable<AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name2');
        self::assertSame([], iterator_to_array($envelopes));

        self::assertSame(['queue_name', 'queue_name2'], $consumedQueues);

        $consumer1 = $this->getConsumer($connection, 'queue_name');
        $consumer2 = $this->getConsumer($connection, 'queue_name2');

        self::assertNotSame($consumer1, $consumer2);

        $consumerTagProperty = new ReflectionProperty(AmqpConsumer::class, 'consumerTag');
        self::assertSame('consumer-tag-1', $consumerTagProperty->getValue($consumer1));
        self::assertSame('consumer-tag-2', $consumerTagProperty->getValue($consumer2));

        $connection->close();

        self::assertNull($consumerTagProperty->getValue($consumer1));
        self::assertNull($consumerTagProperty->getValue($consumer2));
    }

    public function testStartConsumersSubscribesWithoutWaiting(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
                'queue_name2' => new QueueConfig(name: 'queue_name2'),
            ],
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
        $amqpConnection->method('isConnected')->willReturn(true);

        $consumedQueues = [];

        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->expects(self::never())
            ->method('wait');
        $amqpChannel->expects(self::exactly(2))
            ->method('basic_consume')
            ->willReturnCallback(
                static function (string $queue, mixed ...$_args) use (&$consumedQueues): string {
                    $consumedQueues[] = $queue;

                    return $queue === 'queue_name' ? 'consumer-tag-1' : 'consumer-tag-2';
                },
            );

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->startConsumers();

        self::assertSame(['queue_name', 'queue_name2'], $consumedQueues);

        $connection->close();
    }

    public function testListenEnablesExternalWaitAndStartsConsumers(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->expects(self::never())
            ->method('wait');
        $amqpChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-1');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        self::assertFalse($connection->isExternalWaitEnabled());

        $connection->listen();

        self::assertTrue($connection->isExternalWaitEnabled());

        $connection->close();
    }

    public function testStartConsumersSkipsQueuesThatAreNotConfigured(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturnCallback(static function (string $queue): string {
                self::assertSame('queue_name', $queue);

                return 'consumer-tag-1';
            });

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->startConsumers(['queue_name', 'other_transport_queue']);

        $connection->close();
    }

    public function testEnableExternalWaitIsCallableFromOutsideTheConnection(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: ['queue_name' => new QueueConfig(name: 'queue_name')],
        ));

        self::assertFalse($connection->isExternalWaitEnabled());

        $connection->enableExternalWait();

        self::assertTrue($connection->isExternalWaitEnabled());
    }

    public function testGetWaitTimeoutUsesTheShortestQueueTimeout(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            waitTimeout: 5,
            queues: [
                'slow' => new QueueConfig(name: 'slow', waitTimeout: 5),
                'fast' => new QueueConfig(name: 'fast', waitTimeout: 2),
            ],
        ));

        self::assertSame(2.0, $connection->getWaitTimeout());
    }

    public function testGetWaitTimeoutCastsAnIntegerConnectionTimeout(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            waitTimeout: 5,
            queues: [],
        ));

        self::assertSame(5.0, $connection->getWaitTimeout());
    }

    public function testUnregisterFromWaitCoordinatorNotifiesTheCoordinatorAndClearsTheFlag(): void
    {
        [$connection, $coordinator] = $this->createConnectionRegisteredWithCoordinator();

        $coordinator->expects(self::once())
            ->method('unregister')
            ->with($connection);

        $connection->unregisterFromWaitCoordinator();

        self::assertFalse($connection->isRegisteredWithWaitCoordinator());
    }

    public function testUnregisterFromWaitCoordinatorIsSafeWhenNoCoordinatorIsSet(): void
    {
        $connection = $this->createConnectionWithStubs();

        $connection->unregisterFromWaitCoordinator();

        self::assertFalse($connection->isRegisteredWithWaitCoordinator());
    }

    public function testWaitForDeliveriesLogsWhenACoordinatorIsRegistered(): void
    {
        $logger      = new CollectingLogger();
        $factory     = $this->createStub(AmqpConnectionFactory::class);
        $amqp        = $this->createStub(AMQPStreamConnection::class);
        $channel     = $this->createMock(AMQPChannel::class);
        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);

        $factory->method('create')->willReturn($amqp);
        $amqp->method('channel')->willReturn($channel);
        $amqp->method('isConnected')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('basic_consume')->willReturn('tag');
        $channel->expects(self::never())
            ->method('wait');
        $coordinator->expects(self::once())
            ->method('register');
        $coordinator->expects(self::once())
            ->method('wait')
            ->with(1.5, true);

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $factory,
            connectionConfig: new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                queues: [
                    'queue_name' => new QueueConfig(name: 'queue_name'),
                ],
            ),
            logger: $logger,
            debug: new Debug($logger, true),
        );
        $connection->setWaitCoordinator($coordinator);
        $connection->startConsumers();
        $connection->waitForDeliveries(1.5);

        self::assertSame(
            [
                'timeout' => 1.5,
                'coalesce' => true,
            ],
            $logger->contextFor('Waiting for deliveries through the wait coordinator'),
        );
    }

    public function testWaitForDeliveriesLogsANonCoalescedCoordinatorWait(): void
    {
        $logger      = new CollectingLogger();
        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('wait')
            ->with(0.5, false);

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $this->createStub(AmqpConnectionFactory::class),
            connectionConfig: $this->getDefaultConfig(),
            logger: $logger,
            debug: new Debug($logger, true),
        );
        $connection->setWaitCoordinator($coordinator);

        $connection->waitForDeliveries(0.5, false);

        self::assertSame(
            [
                'timeout' => 0.5,
                'coalesce' => false,
            ],
            $logger->contextFor('Waiting for deliveries through the wait coordinator'),
        );
    }

    public function testWaitForDeliveriesLogsWhenNoCoordinatorIsRegistered(): void
    {
        $logger  = new CollectingLogger();
        $factory = $this->createStub(AmqpConnectionFactory::class);
        $amqp    = $this->createStub(AMQPStreamConnection::class);
        $channel = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqp);
        $amqp->method('channel')->willReturn($channel);
        $amqp->method('isConnected')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('hasPendingMethods')->willReturn(false);
        $channel->method('basic_consume')->willReturn('tag');
        $channel->expects(self::once())
            ->method('wait')
            ->with(
                allowed_methods: null,
                non_blocking: true,
            );

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $factory,
            connectionConfig: new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                queues: [
                    'queue_name' => new QueueConfig(name: 'queue_name'),
                ],
            ),
            logger: $logger,
            debug: new Debug($logger, true),
        );
        $connection->startConsumers();
        $connection->waitForDeliveries(0.5);

        self::assertSame(
            ['timeout' => 0.5],
            $logger->contextFor('Waiting without a coordinator; draining only'),
        );
    }

    public function testCloseKeepsWaitCoordinatorRegistration(): void
    {
        [$connection, $coordinator] = $this->createConnectionRegisteredWithCoordinator();

        $coordinator->expects(self::never())
            ->method('unregister');

        $connection->close();

        self::assertTrue($connection->isRegisteredWithWaitCoordinator());
    }

    public function testConsumeReconnectsInsideGetWhenWaitCoordinatorClosesTheConnection(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory       = $this->createMock(AmqpConnectionFactory::class);
        $firstAmqp     = $this->createMock(AMQPStreamConnection::class);
        $secondAmqp    = $this->createStub(AMQPStreamConnection::class);
        $firstChannel  = $this->createMock(AMQPChannel::class);
        $secondChannel = $this->createMock(AMQPChannel::class);
        $logger        = new CollectingLogger();
        $holder        = new class {
            public Connection|null $connection = null;

            public int $waits = 0;
        };

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($firstAmqp, $secondAmqp);

        $firstAmqp->method('channel')->willReturn($firstChannel);
        $firstAmqp->method('isConnected')->willReturn(true);
        $firstAmqp->expects(self::once())
            ->method('close');
        $secondAmqp->method('channel')->willReturn($secondChannel);
        $secondAmqp->method('isConnected')->willReturn(true);

        $firstChannel->method('is_open')->willReturn(true);
        $firstChannel->method('is_consuming')->willReturn(true);
        $firstChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('tag-1');
        $secondChannel->method('is_open')->willReturn(true);
        $secondChannel->method('is_consuming')->willReturn(true);
        $secondChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('tag-2');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register');
        $coordinator->expects(self::exactly(2))
            ->method('wait')
            ->willReturnCallback(static function () use ($holder): void {
                $holder->waits++;
                if ($holder->waits === 1) {
                    $holder->connection?->close();
                }
            });

        $connection         = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
            debug: new Debug($logger, true),
        );
        $holder->connection = $connection;
        $connection->setWaitCoordinator($coordinator);
        $connection->startConsumers();

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
        self::assertTrue($connection->isConnected());
        self::assertTrue($logger->hasTemplate('Reconnecting inside get() after the wait closed the connection'));
    }

    public function testConsumeDoesNotReconnectWhenTheWaitTimesOutWhileConnected(): void
    {
        $logger                     = new CollectingLogger();
        [$connection, $coordinator] = $this->createConnectionRegisteredWithCoordinator();
        $coordinator->expects(self::once())
            ->method('wait');

        $debug = new ReflectionProperty(Connection::class, 'debug');
        $debug->setValue($connection, new Debug($logger, true));

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
        self::assertTrue($connection->isConnected());
        self::assertFalse($logger->hasTemplate('Reconnecting inside get() after the wait closed the connection'));
    }

    public function testConsumeDoesNotReconnectInsideAStandaloneGet(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory = $this->createMock(AmqpConnectionFactory::class);
        $amqp    = $this->createMock(AMQPStreamConnection::class);
        $channel = $this->createMock(AMQPChannel::class);
        $logger  = new CollectingLogger();

        $factory->expects(self::once())
            ->method('create')
            ->willReturn($amqp);
        $amqp->method('channel')->willReturn($channel);
        $amqp->method('isConnected')->willReturn(true);
        $amqp->expects(self::once())
            ->method('close');
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('basic_consume')->willReturn('tag-1');
        $channel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
            debug: new Debug($logger, true),
        );

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
        self::assertFalse($connection->isConnected());
        self::assertFalse($logger->hasTemplate('Reconnecting inside get() after the wait closed the connection'));
    }

    public function testConsumeReconnectsInsideGetWhenExternalWaitDrainClosesTheConnection(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory       = $this->createMock(AmqpConnectionFactory::class);
        $firstAmqp     = $this->createMock(AMQPStreamConnection::class);
        $secondAmqp    = $this->createStub(AMQPStreamConnection::class);
        $firstChannel  = $this->createMock(AMQPChannel::class);
        $secondChannel = $this->createStub(AMQPChannel::class);
        $logger        = new CollectingLogger();

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($firstAmqp, $secondAmqp);

        $firstAmqp->method('channel')->willReturn($firstChannel);
        $firstAmqp->method('isConnected')->willReturn(true);
        $firstAmqp->expects(self::once())
            ->method('close');
        $secondAmqp->method('channel')->willReturn($secondChannel);
        $secondAmqp->method('isConnected')->willReturn(true);

        $firstChannel->method('is_open')->willReturn(true);
        $firstChannel->method('is_consuming')->willReturn(true);
        $firstChannel->method('hasPendingMethods')->willReturn(true);
        $firstChannel->method('basic_consume')->willReturn('tag-1');
        $firstChannel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));
        $secondChannel->method('is_open')->willReturn(true);
        $secondChannel->method('is_consuming')->willReturn(true);
        $secondChannel->method('basic_consume')->willReturn('tag-2');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
            debug: new Debug($logger, true),
        );
        $connection->enableExternalWait();
        $connection->startConsumers();

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
        self::assertTrue($connection->isConnected());
        self::assertTrue($logger->hasTemplate('Reconnecting inside get() after the wait closed the connection'));
    }

    public function testConsumeReturnsEmptyWhenReconnectAfterWaitFails(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory = $this->createMock(AmqpConnectionFactory::class);
        $amqp    = $this->createMock(AMQPStreamConnection::class);
        $channel = $this->createStub(AMQPChannel::class);
        $logger  = new CollectingLogger();
        $holder  = new class {
            public Connection|null $connection = null;
        };
        $creates = new class {
            public int $n = 0;
        };

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnCallback(static function () use ($amqp, $creates): AMQPStreamConnection {
                $creates->n++;
                if ($creates->n === 1) {
                    return $amqp;
                }

                throw new AMQPIOException('connection refused');
            });

        $amqp->method('channel')->willReturn($channel);
        $amqp->method('isConnected')->willReturn(true);
        $amqp->expects(self::once())
            ->method('close');
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('basic_consume')->willReturn('tag-1');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register');
        $coordinator->expects(self::once())
            ->method('wait')
            ->willReturnCallback(static function () use ($holder): void {
                $holder->connection?->close();
            });

        $connection         = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
            debug: new Debug($logger, true),
        );
        $holder->connection = $connection;
        $connection->setWaitCoordinator($coordinator);
        $connection->startConsumers();

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
        self::assertFalse($connection->isConnected());
        self::assertTrue($logger->hasTemplate('Reconnecting inside get() after the wait closed the connection'));
        self::assertSame(
            'connection refused',
            $logger->contextFor('AMQP exception occurred while restarting consumer after wait: {message}')['message'] ?? null,
        );
        self::assertArrayHasKey(
            'exception',
            $logger->contextFor('AMQP exception occurred while restarting consumer after wait: {message}'),
        );
    }

    public function testConsumeReturnsEmptyWhenReconnectAfterWaitFailsWithoutALogger(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory = $this->createMock(AmqpConnectionFactory::class);
        $amqp    = $this->createMock(AMQPStreamConnection::class);
        $channel = $this->createStub(AMQPChannel::class);
        $holder  = new class {
            public Connection|null $connection = null;
        };
        $creates = new class {
            public int $n = 0;
        };

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnCallback(static function () use ($amqp, $creates): AMQPStreamConnection {
                $creates->n++;
                if ($creates->n === 1) {
                    return $amqp;
                }

                throw new AMQPIOException('connection refused');
            });

        $amqp->method('channel')->willReturn($channel);
        $amqp->method('isConnected')->willReturn(true);
        $amqp->expects(self::once())
            ->method('close');
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('basic_consume')->willReturn('tag-1');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register');
        $coordinator->expects(self::once())
            ->method('wait')
            ->willReturnCallback(static function () use ($holder): void {
                $holder->connection?->close();
            });

        $connection         = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $holder->connection = $connection;
        $connection->setWaitCoordinator($coordinator);
        $connection->startConsumers();

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
        self::assertFalse($connection->isConnected());
    }

    public function testStartConsumersHonorsAnExplicitQueueList(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
                'queue_name2' => new QueueConfig(name: 'queue_name2'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturnCallback(static function (string $queue): string {
                self::assertSame('queue_name', $queue);

                return 'consumer-tag-1';
            });

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->startConsumers(['queue_name']);

        $connection->close();
    }

    public function testStartConsumersContinuesAfterAnUnconfiguredQueue(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturnCallback(static function (string $queue): string {
                self::assertSame('queue_name', $queue);

                return 'consumer-tag-1';
            });

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->startConsumers(['missing', 'queue_name']);

        $connection->close();
    }

    public function testStartConsumersReusesAnExistingConsumer(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer-tag-1');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->startConsumers();
        $connection->startConsumers();

        $connection->close();
    }

    public function testStartConsumersRegistersWhenTheBrokerIsDown(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory     = $this->createStub(AmqpConnectionFactory::class);
        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $logger      = $this->createMock(LoggerInterface::class);
        $factory->method('create')
            ->willThrowException(new AMQPIOException('connection refused'));
        $coordinator->expects(self::once())
            ->method('register');
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'AMQP exception occurred while starting consumers: {message}',
                self::callback(static function (array $context): bool {
                    return $context['message'] === 'connection refused'
                        && $context['exception'] instanceof TransportException;
                }),
            );

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
        );
        $connection->setWaitCoordinator($coordinator);
        $connection->startConsumers();

        self::assertTrue($connection->isRegisteredWithWaitCoordinator());
    }

    public function testStartConsumersRegistersWhenTheBrokerIsDownWithoutALogger(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory     = $this->createStub(AmqpConnectionFactory::class);
        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $factory->method('create')
            ->willThrowException(new AMQPIOException('connection refused'));
        $coordinator->expects(self::once())
            ->method('register');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->setWaitCoordinator($coordinator);
        $connection->startConsumers();

        self::assertTrue($connection->isRegisteredWithWaitCoordinator());
    }

    public function testStartConsumersLogsWhenAutoSetupFails(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: true,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory     = $this->createStub(AmqpConnectionFactory::class);
        $coordinator = $this->createStub(ConsumerWaitCoordinator::class);
        $logger      = $this->createMock(LoggerInterface::class);
        $factory->method('create')
            ->willThrowException(new AMQPIOException('connection refused'));
        $logger->expects(self::exactly(2))
            ->method('warning')
            ->with(
                'AMQP exception occurred while starting consumers: {message}',
                self::callback(static function (array $context): bool {
                    return $context['message'] === 'connection refused'
                        && $context['exception'] instanceof TransportException;
                }),
            );

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
        );
        $connection->setWaitCoordinator($coordinator);
        $connection->startConsumers();

        self::assertTrue($connection->isRegisteredWithWaitCoordinator());
    }

    public function testStartConsumersRegistersWhenAutoSetupFailsWithoutALogger(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: true,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory     = $this->createStub(AmqpConnectionFactory::class);
        $coordinator = $this->createStub(ConsumerWaitCoordinator::class);
        $factory->method('create')
            ->willThrowException(new AMQPIOException('connection refused'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->setWaitCoordinator($coordinator);
        $connection->startConsumers();

        self::assertTrue($connection->isRegisteredWithWaitCoordinator());
    }

    public function testConsumeReturnsEmptyWhenSetupFailsInsideAWorker(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: true,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory = $this->createStub(AmqpConnectionFactory::class);
        $logger  = $this->createMock(LoggerInterface::class);
        $factory->method('create')
            ->willThrowException(new AMQPIOException('connection refused'));
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'AMQP exception occurred while starting consumers: {message}',
                self::callback(static function (array $context): bool {
                    return $context['message'] === 'connection refused'
                        && $context['exception'] instanceof TransportException;
                }),
            );

        $connection  = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
        );
        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('wait');
        $connection->setWaitCoordinator($coordinator);
        $registered = new ReflectionProperty(Connection::class, 'registeredWithCoordinator');
        $registered->setValue($connection, true);

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
    }

    public function testConsumeReturnsEmptyWhenSetupFailsInsideAWorkerWithoutALogger(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: true,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory = $this->createStub(AmqpConnectionFactory::class);
        $factory->method('create')
            ->willThrowException(new AMQPIOException('connection refused'));

        $connection  = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('wait');
        $connection->setWaitCoordinator($coordinator);
        $registered = new ReflectionProperty(Connection::class, 'registeredWithCoordinator');
        $registered->setValue($connection, true);

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
    }

    public function testConsumePropagatesSetupFailureOutsideAWorker(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: true,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory = $this->createStub(AmqpConnectionFactory::class);
        $factory->method('create')
            ->willThrowException(new AMQPIOException('connection refused'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('connection refused');

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');
        iterator_to_array($envelopes);
    }

    public function testConsumeReturnsEmptyWhenSetupFailsWithExternalWait(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: true,
            confirmEnabled: false,
            retries: 0,
            retryWaitTime: 0,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory = $this->createStub(AmqpConnectionFactory::class);
        $factory->method('create')
            ->willThrowException(new AMQPIOException('connection refused'));

        $connection  = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::never())
            ->method('wait');
        $connection->setWaitCoordinator($coordinator);
        $connection->enableExternalWait();
        $registered = new ReflectionProperty(Connection::class, 'registeredWithCoordinator');
        $registered->setValue($connection, true);

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $connection->consume('queue_name');

        self::assertCount(0, iterator_to_array($envelopes));
    }

    public function testDrainConsumerChannelReturnsWhenNonBlockingWaitFindsNoData(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $channel        = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($channel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::never())
            ->method('close');
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->expects(self::once())
            ->method('wait')
            ->with(
                allowed_methods: null,
                non_blocking: true,
            )
            ->willReturn(null);

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->consumerChannel();

        $start = hrtime(true);
        self::assertFalse($connection->drainConsumerChannel());
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(200, $elapsedMs);
    }

    public function testDrainConsumerChannelReadsWhileMethodsArePending(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $channel        = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($channel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('hasPendingMethods')
            ->willReturnOnConsecutiveCalls(true, true, false);
        $channel->expects(self::exactly(3))
            ->method('wait')
            ->with(
                allowed_methods: null,
                non_blocking: true,
            )
            ->willReturn(null);

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->consumerChannel();
        self::assertTrue($connection->drainConsumerChannel());
    }

    public function testDrainConsumerChannelStopsOnTimeoutException(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $channel        = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($channel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::never())
            ->method('close');
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('hasPendingMethods')
            ->willReturn(true);
        $channel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPTimeoutException('no more frames'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->consumerChannel();
        $connection->drainConsumerChannel();
    }

    public function testDrainConsumerChannelDoesNothingWhenTheChannelIsNotConsuming(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $channel        = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($channel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(false);
        $channel->expects(self::never())
            ->method('wait');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->consumerChannel();
        $connection->drainConsumerChannel();
    }

    public function testDrainConsumerChannelDoesNothingWhenTheChannelIsClosed(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $channel        = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($channel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $channel->method('is_open')->willReturn(false);
        $channel->method('is_consuming')->willReturn(true);
        $channel->expects(self::never())
            ->method('wait');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->consumerChannel();
        $connection->drainConsumerChannel();
    }

    public function testDrainConsumerChannelClosesOnAmqpException(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $channel        = $this->createMock(AMQPChannel::class);
        $logger         = $this->createMock(LoggerInterface::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($channel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::once())
            ->method('close');
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('hasPendingMethods')
            ->willReturn(true);
        $channel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPChannelClosedException('channel closed'));
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'AMQP exception occurred while draining consumer channel: {message}',
                self::callback(static function (array $context): bool {
                    return $context['message'] === 'channel closed'
                        && $context['exception'] instanceof AMQPChannelClosedException;
                }),
            );

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
        );
        $connection->consumerChannel();
        $connection->drainConsumerChannel();
    }

    public function testDrainConsumerChannelClosesOnAmqpExceptionWithoutALogger(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $channel        = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($channel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::once())
            ->method('close');
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('hasPendingMethods')
            ->willReturn(true);
        $channel->expects(self::once())
            ->method('wait')
            ->willThrowException(new AMQPChannelClosedException('channel closed'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->consumerChannel();
        $connection->drainConsumerChannel();
    }

    public function testDrainConsumerChannelReadsQueuedFrames(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $channel        = $this->createMock(AMQPChannel::class);
        $frames         = new SplQueue();
        $frames->enqueue('frame');

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($channel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('is_consuming')->willReturn(true);
        $channel->method('hasPendingMethods')
            ->willReturn(false);
        $channel->expects(self::exactly(2))
            ->method('wait')
            ->willReturnCallback(static function () use ($frames): void {
                if (! $frames->isEmpty()) {
                    $frames->dequeue();
                }
            });

        $frameQueue = new ReflectionProperty(AMQPChannel::class, 'frame_queue');
        $frameQueue->setValue($channel, $frames);

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->consumerChannel();

        self::assertTrue($connection->drainConsumerChannel());
    }

    public function testDrainConsumerChannelReadsWhenTheSocketIsReadable(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);
        fwrite($right, 'x');

        try {
            $connectionConfig = new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                queues: [
                    'queue_name' => new QueueConfig(name: 'queue_name'),
                ],
            );

            $factory        = $this->createStub(AmqpConnectionFactory::class);
            $amqpConnection = $this->createStub(AMQPStreamConnection::class);
            $channel        = $this->createMock(AMQPChannel::class);

            $factory->method('create')->willReturn($amqpConnection);
            $amqpConnection->method('channel')->willReturn($channel);
            $amqpConnection->method('isConnected')->willReturn(true);
            $channel->method('is_open')->willReturn(true);
            $channel->method('is_consuming')->willReturn(true);
            $channel->method('hasPendingMethods')
                ->willReturn(false);
            $channel->expects(self::exactly(2))
                ->method('wait')
                ->willReturnCallback(static function () use ($left): void {
                    stream_get_contents($left);
                });

            $connection = new Connection(
                retryFactory: new RetryFactory(),
                amqpConnectionFactory: $factory,
                connectionConfig: $connectionConfig,
            );
            $connection->consumerChannel();
            $this->bindAmqpSocket($connection, $amqpConnection, $left);

            self::assertTrue($connection->drainConsumerChannel());
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testDrainConsumerChannelDoesNotBlockOnAnIdleSocket(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $connectionConfig = new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                queues: [
                    'queue_name' => new QueueConfig(name: 'queue_name'),
                ],
            );

            $factory        = $this->createStub(AmqpConnectionFactory::class);
            $amqpConnection = $this->createStub(AMQPStreamConnection::class);
            $channel        = $this->createMock(AMQPChannel::class);

            $factory->method('create')->willReturn($amqpConnection);
            $amqpConnection->method('channel')->willReturn($channel);
            $amqpConnection->method('isConnected')->willReturn(true);
            $channel->method('is_open')->willReturn(true);
            $channel->method('is_consuming')->willReturn(true);
            $channel->method('hasPendingMethods')
                ->willReturn(false);
            $channel->expects(self::once())
                ->method('wait')
                ->willReturn(null);

            $connection = new Connection(
                retryFactory: new RetryFactory(),
                amqpConnectionFactory: $factory,
                connectionConfig: $connectionConfig,
            );
            $connection->consumerChannel();
            $this->bindAmqpSocket($connection, $amqpConnection, $left);

            $start = hrtime(true);
            self::assertFalse($connection->drainConsumerChannel());
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            self::assertLessThan(300, $elapsedMs, 'Idle socket poll waited instead of returning immediately');
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testGetConsumerSocketReturnsNullWhenDisconnected(): void
    {
        $connection = $this->createConnectionWithStubs();

        self::assertNull($connection->getConsumerSocket());
    }

    public function testGetConsumerSocketReturnsNullWhenTheAmqpConnectionIsClosed(): void
    {
        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpConnection->method('isConnected')
            ->willReturn(false);

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: new ConnectionConfig(autoSetup: false, confirmEnabled: false),
        );

        $property = new ReflectionProperty(Connection::class, 'connection');
        $property->setValue($connection, $amqpConnection);

        self::assertNull($connection->getConsumerSocket());
    }

    public function testGetConsumerSocketReturnsTheIoSock(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;

        try {
            $factory        = $this->createStub(AmqpConnectionFactory::class);
            $amqpConnection = $this->createStub(AMQPStreamConnection::class);
            $channel        = $this->createStub(AMQPChannel::class);

            $factory->method('create')->willReturn($amqpConnection);
            $amqpConnection->method('channel')->willReturn($channel);
            $amqpConnection->method('isConnected')
                ->willReturn(true);

            $connection = new Connection(
                retryFactory: new RetryFactory(),
                amqpConnectionFactory: $factory,
                connectionConfig: new ConnectionConfig(autoSetup: false, confirmEnabled: false),
            );
            $connection->consumerChannel();
            $this->bindAmqpSocket($connection, $amqpConnection, $left);

            self::assertSame($left, $connection->getConsumerSocket());
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    private function bindAmqpSocket(
        Connection $connection,
        AMQPStreamConnection $amqp,
        mixed $socket,
    ): void {
        $io       = new stdClass();
        $io->sock = $socket;

        $ioProperty = new ReflectionProperty(AMQPStreamConnection::class, 'io');
        $ioProperty->setValue($amqp, $io);

        $connectionProperty = new ReflectionProperty(Connection::class, 'connection');

        self::assertSame($amqp, $connectionProperty->getValue($connection));
    }

    /** @return array{Connection, ConsumerWaitCoordinator&MockObject} */
    private function createConnectionRegisteredWithCoordinator(): array
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            queues: [
                'queue_name' => new QueueConfig(name: 'queue_name'),
            ],
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel    = $this->createStub(AMQPChannel::class);
        $coordinator    = $this->createMock(ConsumerWaitCoordinator::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpChannel->method('is_open')->willReturn(true);
        $amqpChannel->method('basic_consume')->willReturn('consumer-tag-1');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );
        $connection->setWaitCoordinator($coordinator);
        $coordinator->expects(self::once())
            ->method('register')
            ->with($connection);
        $connection->startConsumers();

        self::assertTrue($connection->isRegisteredWithWaitCoordinator());

        return [$connection, $coordinator];
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

    public function testPublishParameterDefaultsAreImmediateAndUnbatched(): void
    {
        $parameters = (new ReflectionMethod(Connection::class, 'publish'))->getParameters();

        self::assertSame(0, $parameters[2]->getDefaultValue());
        self::assertSame(1, $parameters[3]->getDefaultValue());
    }

    public function testPublishSetsUpTopologyOnceWhenAutoSetupIsEnabled(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $amqpChannel->expects(self::once())
            ->method('exchange_declare');
        $amqpChannel->expects(self::once())
            ->method('queue_declare');
        $amqpChannel->expects(self::exactly(2))
            ->method('basic_publish');

        $connection->publish(body: 'one');
        $connection->publish(body: 'two');
    }

    public function testPublishUsesStampAttributesHeadersAndRoutingKey(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(
                name: 'exchange_name',
                defaultPublishRoutingKey: 'default-key',
            ),
        ));

        $amqpChannel->expects(self::once())
            ->method('basic_publish')
            ->with(
                self::callback(static function (AMQPMessage $message): bool {
                    self::assertSame('application/json', $message->get('content_type'));
                    self::assertSame(5, $message->get('priority'));
                    $headers = $message->get('application_headers');
                    self::assertInstanceOf(AMQPTable::class, $headers);
                    self::assertSame([
                        'from-stamp' => 'a',
                        'from-publish' => 'b',
                    ], $headers->getNativeData());

                    return true;
                }),
                'exchange_name',
                'stamp-key',
            );

        $connection->publish(
            body: 'test body',
            headers: ['from-publish' => 'b'],
            amqpStamp: new AmqpStamp('stamp-key', [
                'content_type' => 'application/json',
                'priority' => 5,
                'headers' => ['from-stamp' => 'a'],
            ]),
        );
    }

    public function testPublishBatchesWithTheStampRoutingKey(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $amqpChannel->expects(self::once())
            ->method('batch_basic_publish')
            ->with(
                self::isInstanceOf(AMQPMessage::class),
                'exchange_name',
                'stamp-key',
            );

        $connection->publish(
            body: 'batched',
            batchSize: 2,
            amqpStamp: new AmqpStamp('stamp-key'),
        );
        $connection->flush();
    }

    public function testDelayedPublishUsesRoutingKeyForDeadLetterBinding(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->with(
                queue: 'delay_exchange_name_orders_5000_delay',
                passive: false,
                durable: false,
                exclusive: false,
                auto_delete: true,
                nowait: false,
                arguments: new AMQPTable([
                    'x-message-ttl' => 5000,
                    'x-expires' => 15000,
                    'x-dead-letter-exchange' => 'exchange_name',
                    'x-dead-letter-routing-key' => 'orders',
                ]),
            );

        $connection->publish(
            body: 'test body',
            delayInMs: 5000,
            amqpStamp: new AmqpStamp('orders'),
        );
    }

    public function testDelayedPublishSetsUpDelayExchangeOnce(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $amqpChannel->expects(self::once())
            ->method('exchange_declare');

        $connection->publish(body: 'one', delayInMs: 1000);
        $connection->publish(body: 'two', delayInMs: 2000);
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

    public function testPublishWithDelayDurableDeclaresDurableDelayQueue(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
            delay: new DelayConfig(durable: true),
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

    public function testDirectPublishFailsWhenTheBrokerNacksTheMessage(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        /** @var callable(AMQPMessage): void|null $nackHandler */
        $nackHandler = null;

        $amqpChannel->expects(self::once())
            ->method('set_nack_handler')
            ->willReturnCallback(
                static function (callable $handler) use (&$nackHandler): void {
                    $nackHandler = $handler;
                },
            );
        $amqpChannel->expects(self::once())
            ->method('basic_publish');
        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willReturnCallback(
                static function () use (&$nackHandler): void {
                    /** @var callable(AMQPMessage): void $handler */
                    $handler = $nackHandler;
                    $handler(new AMQPMessage('nacked message'));
                },
            );

        $previousRetries       = Retry::$defaultRetries;
        Retry::$defaultRetries = 0;

        try {
            try {
                $connection->publish(body: 'nacked message');
                self::fail('Expected the NACKed publish to fail.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                self::assertSame(0, $exception->getCode());
            }
        } finally {
            Retry::$defaultRetries = $previousRetries;
        }
    }

    public function testDirectPublishOpensANewChannelAfterANack(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
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

        $amqpChannel1->method('confirm_select');
        $amqpChannel2->method('confirm_select');
        $amqpChannel1->method('closeIfDisconnected')->willReturn(true);

        /** @var callable(AMQPMessage): void|null $nackHandler */
        $nackHandler = null;

        $amqpChannel1->expects(self::once())
            ->method('set_nack_handler')
            ->willReturnCallback(
                static function (callable $handler) use (&$nackHandler): void {
                    $nackHandler = $handler;
                },
            );
        $amqpChannel1->expects(self::once())
            ->method('basic_publish');
        $amqpChannel1->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willReturnCallback(
                static function () use (&$nackHandler): void {
                    /** @var callable(AMQPMessage): void $handler */
                    $handler = $nackHandler;
                    $handler(new AMQPMessage('nacked message'));
                },
            );
        $amqpChannel1->expects(self::never())
            ->method('close');

        $amqpChannel2->expects(self::once())
            ->method('basic_publish');
        $amqpChannel2->method('wait_for_pending_acks');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $previousRetries       = Retry::$defaultRetries;
        Retry::$defaultRetries = 0;

        try {
            try {
                $connection->publish(body: 'nacked message');
                self::fail('Expected the NACKed publish to fail.');
            } catch (TransportException) {
            }

            $connection->publish(body: 'retry message');
        } finally {
            Retry::$defaultRetries = $previousRetries;
        }
    }

    public function testFlushDiscardsTheChannelWhenPublishBatchThrowsATransportException(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
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

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new TransportException('publish_batch failed', 0));
        $amqpChannel1->method('closeIfDisconnected')->willReturn(true);

        $amqpChannel2->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel2->expects(self::once())
            ->method('publish_batch');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->publish(body: 'one', batchSize: 3);
        $connection->publish(body: 'two', batchSize: 3);

        try {
            $connection->flush();
            self::fail('Expected publish_batch to fail.');
        } catch (TransportException $exception) {
            self::assertSame('publish_batch failed', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
        }

        $connection->flush();
    }

    public function testFlushDoesNotOpenAChannelWhileTheBrokerIsBlocked(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);
        $blocked        = false;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->method('isBlocked')->willReturnCallback(
            static function () use (&$blocked): bool {
                return $blocked;
            },
        );
        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);

        $amqpChannel->expects(self::never())
            ->method('publish_batch');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->channel();
        $connection->publish(body: 'batched', batchSize: 3);

        $blocked = true;

        $this->expectException(AMQPConnectionBlockedException::class);

        $connection->flush();
    }

    public function testChannelDoesNotCloseRetiredChannelsWhileTheBrokerIsBlocked(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $blocked = false;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->method('isBlocked')->willReturnCallback(
            static function () use (&$blocked): bool {
                return $blocked;
            },
        );
        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);

        $amqpChannel->expects(self::once())
            ->method('basic_publish')
            ->willReturnCallback(
                static function () use (&$blocked): never {
                    $blocked = true;

                    throw new AMQPConnectionBlockedException('Connection blocked');
                },
            );
        $amqpChannel->method('closeIfDisconnected')->willReturn(false);
        $amqpChannel->expects(self::never())
            ->method('close');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        try {
            $connection->publish(body: 'blocked');
            self::fail('Expected the blocked publish to fail.');
        } catch (AMQPConnectionBlockedException) {
        }

        try {
            $connection->channel();
            self::fail('Expected opening a replacement channel to fail while blocked.');
        } catch (AMQPConnectionBlockedException) {
        }
    }

    public function testDirectPublishDoesNotRetryWhenTheBrokerNacksTheMessage(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        /** @var callable(AMQPMessage): void|null $nackHandler */
        $nackHandler = null;

        $amqpChannel->expects(self::once())
            ->method('set_nack_handler')
            ->willReturnCallback(
                static function (callable $handler) use (&$nackHandler): void {
                    $nackHandler = $handler;
                },
            );
        $amqpChannel->expects(self::once())
            ->method('basic_publish');
        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willReturnCallback(
                static function () use (&$nackHandler): void {
                    /** @var callable(AMQPMessage): void $handler */
                    $handler = $nackHandler;
                    $handler(new AMQPMessage('nacked message'));
                },
            );

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 3;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->publish(body: 'nacked message');
                self::fail('Expected the NACKed publish to fail without retrying.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                self::assertSame(0, $exception->getCode());
            }
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testDirectPublishDoesNotRepublishWhenPendingAcksTimeOut(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $amqpChannel->expects(self::once())
            ->method('basic_publish');
        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willThrowException(new AMQPTimeoutException('Confirm timeout'));

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->publish(body: 'confirm body');
                self::fail('Expected the confirm wait to time out.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(AMQPTimeoutException::class, $exception->getPrevious());
            }
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testDirectPublishDoesNotRepublishWhenPendingAcksTimeOutWhileRetriesRemain(): void
    {
        [$connection, $amqpConnection, $amqpChannel] = $this->createConnectionWithAllMocks(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $amqpChannel->method('confirm_select');
        $amqpChannel->expects(self::exactly(2))
            ->method('basic_publish');
        $amqpChannel->expects(self::exactly(2))
            ->method('wait_for_pending_acks')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new AMQPTimeoutException('Confirm timeout')),
                true,
            );

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 3;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->publish(body: 'confirm body');
                self::fail('Expected the confirm wait to time out without republishing.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(AMQPTimeoutException::class, $exception->getPrevious());
            }

            $connection->publish(body: 'confirm body 2');
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testDirectPublishRetriesWhenTheConnectionCloses(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
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
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $amqpChannel1->method('confirm_select');
        $amqpChannel1->expects(self::once())
            ->method('basic_publish')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));
        $amqpChannel1->expects(self::never())
            ->method('wait_for_pending_acks');

        $amqpChannel2->method('confirm_select');
        $amqpChannel2->expects(self::once())
            ->method('basic_publish');
        $amqpChannel2->expects(self::once())
            ->method('wait_for_pending_acks');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->publish(body: 'retried body');
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testDirectPublishDoesNotRetryWhenRetriesAreDisabled(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            retriesEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($amqpChannel);
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $amqpChannel->method('confirm_select');
        $amqpChannel->expects(self::once())
            ->method('basic_publish')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));
        $amqpChannel->expects(self::never())
            ->method('wait_for_pending_acks');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        try {
            $connection->publish(body: 'lost on failure');
            self::fail('Expected the publish to fail without retrying.');
        } catch (TransportException $exception) {
            self::assertSame('Broken pipe or closed connection', $exception->getMessage());
            self::assertInstanceOf(AMQPConnectionClosedException::class, $exception->getPrevious());
        }
    }

    public function testDirectPublishRetriesWhenTheChannelCloses(): void
    {
        $this->assertDirectPublishRetriesRecoverableFailure(
            new AMQPChannelClosedException('Channel connection is closed.'),
            expectReconnect: false,
        );
    }

    public function testDirectPublishRetriesWhenIOFails(): void
    {
        $this->assertDirectPublishRetriesRecoverableFailure(
            new AMQPIOException('Broken pipe'),
            expectReconnect: false,
        );
    }

    public function testDirectPublishReconnectsWhenTheConnectionIsDead(): void
    {
        $this->assertDirectPublishRetriesRecoverableFailure(
            new AMQPConnectionClosedException('Broken pipe or closed connection'),
            expectReconnect: true,
        );
    }

    public function testDirectPublishRetriesWhenTheConnectionClosesWhileWaitingForPendingAcks(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
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
        $amqpConnection->expects(self::never())
            ->method('reconnect');

        $amqpChannel1->method('confirm_select');
        $amqpChannel1->expects(self::once())
            ->method('basic_publish');
        $amqpChannel1->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willThrowException(new AMQPConnectionClosedException('Connection closed while waiting for confirms'));

        $amqpChannel2->method('confirm_select');
        $amqpChannel2->expects(self::once())
            ->method('basic_publish');
        $amqpChannel2->expects(self::once())
            ->method('wait_for_pending_acks');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->publish(body: 'confirm then closed');
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }
    }

    public function testBatchRemainsBufferedWhenTheBrokerNacksAMessage(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        /** @var callable(AMQPMessage): void|null $nackHandler */
        $nackHandler = null;

        $amqpChannel->expects(self::once())
            ->method('set_nack_handler')
            ->willReturnCallback(
                static function (callable $handler) use (&$nackHandler): void {
                    $nackHandler = $handler;
                },
            );
        $amqpChannel->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel->expects(self::once())
            ->method('publish_batch');
        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willReturnCallback(
                static function () use (&$nackHandler): void {
                    /** @var callable(AMQPMessage): void $handler */
                    $handler = $nackHandler;
                    $handler(new AMQPMessage('nacked message'));
                },
            );

        $connection->publish(body: 'batch body 1', batchSize: 3);
        $connection->publish(body: 'batch body 2', batchSize: 3);

        $previousRetries       = Retry::$defaultRetries;
        Retry::$defaultRetries = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected the NACKed batch to fail.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                self::assertSame(0, $exception->getCode());
            }
        } finally {
            Retry::$defaultRetries = $previousRetries;
        }

        self::assertCount(2, $this->getPendingBatchMessages($connection));
    }

    public function testBatchFlushOpensANewChannelAfterANack(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
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

        $amqpChannel1->method('confirm_select');
        $amqpChannel2->method('confirm_select');
        $amqpChannel1->method('closeIfDisconnected')->willReturn(true);

        /** @var callable(AMQPMessage): void|null $nackHandler */
        $nackHandler = null;

        $amqpChannel1->expects(self::once())
            ->method('set_nack_handler')
            ->willReturnCallback(
                static function (callable $handler) use (&$nackHandler): void {
                    $nackHandler = $handler;
                },
            );
        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel1->expects(self::once())
            ->method('publish_batch');
        $amqpChannel1->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willReturnCallback(
                static function () use (&$nackHandler): void {
                    /** @var callable(AMQPMessage): void $handler */
                    $handler = $nackHandler;
                    $handler(new AMQPMessage('nacked message'));
                },
            );
        $amqpChannel1->expects(self::never())
            ->method('close');

        $amqpChannel2->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel2->expects(self::once())
            ->method('publish_batch');
        $amqpChannel2->method('wait_for_pending_acks');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->publish(body: 'batch body 1', batchSize: 3);
        $connection->publish(body: 'batch body 2', batchSize: 3);

        $previousRetries       = Retry::$defaultRetries;
        Retry::$defaultRetries = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected the NACKed batch to fail.');
            } catch (TransportException) {
            }

            $connection->flush();
        } finally {
            Retry::$defaultRetries = $previousRetries;
        }
    }

    public function testBatchDoesNotRepublishWhenTheBrokerNacksAMessage(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        /** @var callable(AMQPMessage): void|null $nackHandler */
        $nackHandler = null;

        $amqpChannel->expects(self::once())
            ->method('set_nack_handler')
            ->willReturnCallback(
                static function (callable $handler) use (&$nackHandler): void {
                    $nackHandler = $handler;
                },
            );
        $amqpChannel->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel->expects(self::once())
            ->method('publish_batch');
        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willReturnCallback(
                static function () use (&$nackHandler): void {
                    /** @var callable(AMQPMessage): void $handler */
                    $handler = $nackHandler;
                    $handler(new AMQPMessage('nacked message'));
                },
            );

        $connection->publish(body: 'batch body 1', batchSize: 3);
        $connection->publish(body: 'batch body 2', batchSize: 3);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 3;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected the NACKed batch to fail without republishing.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(PublisherNack::class, $exception->getPrevious());
                self::assertSame(0, $exception->getCode());
            }
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertCount(2, $this->getPendingBatchMessages($connection));
    }

    public function testPublishPreservesThePublishExceptionWhenRollbackAlsoFails(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            transactionsEnabled: true,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel    = $this->createMock(AMQPChannel::class);
        $logger         = $this->createMock(LoggerInterface::class);

        $factory->method('create')->willReturn($amqpConnection);

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

        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'AMQP transaction rollback failed: {message}',
                [
                    'message' => 'Rollback failed',
                    'exception' => $rollbackException,
                ],
            );

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
            logger: $logger,
        );

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

    public function testRepeatedPublishesWhileBrokerIsBlockedDoNotKeepOpeningChannels(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        );

        $factory           = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection    = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel1      = $this->createMock(AMQPChannel::class);
        $amqpChannel2      = $this->createMock(AMQPChannel::class);
        $amqpChannel3      = $this->createMock(AMQPChannel::class);
        $blocked           = false;
        $channel2Publishes = 0;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->method('isBlocked')->willReturnCallback(
            static function () use (&$blocked): bool {
                return $blocked;
            },
        );
        $amqpConnection->expects(self::exactly(3))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2, $amqpChannel3);

        $amqpChannel1->expects(self::once())
            ->method('basic_publish')
            ->willReturnCallback(
                static function () use (&$blocked): never {
                    $blocked = true;

                    throw new AMQPConnectionBlockedException('Connection blocked');
                },
            );
        $amqpChannel1->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(false);
        $amqpChannel1->expects(self::once())
            ->method('close');

        $amqpChannel2->expects(self::exactly(2))
            ->method('basic_publish')
            ->willReturnCallback(
                static function () use (&$blocked, &$channel2Publishes): void {
                    $channel2Publishes++;

                    if ($channel2Publishes === 2) {
                        $blocked = true;

                        throw new AMQPConnectionBlockedException('Connection blocked again');
                    }
                },
            );
        $amqpChannel2->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(false);
        $amqpChannel2->expects(self::once())
            ->method('close');

        $amqpChannel3->expects(self::once())
            ->method('basic_publish');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $connection->publish(body: 'body');
                self::fail('Expected the blocked publish to fail.');
            } catch (AMQPConnectionBlockedException) {
            }
        }

        $blocked = false;

        $connection->publish(body: 'body');

        // A second distinct alarm retires the replacement channel. Attempts during the
        // alarm do not allocate, and recovery closes it before opening another channel.
        try {
            $connection->publish(body: 'body');
            self::fail('Expected the second blocked publish to fail.');
        } catch (AMQPConnectionBlockedException) {
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $connection->publish(body: 'body');
                self::fail('Expected the blocked publish to fail.');
            } catch (AMQPConnectionBlockedException) {
            }
        }

        $blocked = false;

        $connection->publish(body: 'body');
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

    public function testFlushWaitsAgainWithoutRepublishingWhenPendingAcksTimeOut(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest(
            usesSingleChannel: true,
        );

        $body1 = 'confirm body 1';
        $body2 = 'confirm body 2';

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');

        $amqpChannel1->expects(self::once())
            ->method('publish_batch');

        $amqpChannel1->expects(self::exactly(2))
            ->method('wait_for_pending_acks')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new AMQPTimeoutException('Confirm timeout')),
                true,
            );

        $amqpChannel2->expects(self::never())
            ->method('batch_basic_publish');

        $amqpChannel2->expects(self::never())
            ->method('publish_batch');

        $amqpChannel2->expects(self::never())
            ->method('wait_for_pending_acks');

        $connection->publish(body: $body1, batchSize: 3);
        $connection->publish(body: $body2, batchSize: 3);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected the first confirm wait to time out.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(AMQPTimeoutException::class, $exception->getPrevious());
            }

            self::assertCount(2, $this->getPendingBatchMessages($connection));

            $connection->flush();
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertSame([], $this->getPendingBatchMessages($connection));
    }

    public function testConfirmWaitDoesNotNestTheDefaultRetryBudget(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: true,
            confirmTimeout: 5.0,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        ));

        $amqpChannel->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel->expects(self::once())
            ->method('publish_batch');
        $amqpChannel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willThrowException(new AMQPTimeoutException('Confirm timeout'));

        $connection->publish(body: 'confirm body 1', batchSize: 3);
        $connection->publish(body: 'confirm body 2', batchSize: 3);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 3;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected the confirm wait to time out once, not the default retry budget times.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(AMQPTimeoutException::class, $exception->getPrevious());
            }
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertCount(2, $this->getPendingBatchMessages($connection));
    }

    public function testPublishReWaitsAPendingBatchConfirmBeforeSendingANewerMessage(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest(
            usesSingleChannel: true,
        );

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel1->expects(self::once())
            ->method('publish_batch');
        $amqpChannel1->expects(self::exactly(3))
            ->method('wait_for_pending_acks')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new AMQPTimeoutException('Confirm timeout')),
                true,
                true,
            );
        $amqpChannel1->expects(self::once())
            ->method('basic_publish');

        $amqpChannel2->expects(self::never())
            ->method('batch_basic_publish');
        $amqpChannel2->expects(self::never())
            ->method('publish_batch');
        $amqpChannel2->expects(self::never())
            ->method('basic_publish');

        $connection->publish(body: 'confirm body 1', batchSize: 3);
        $connection->publish(body: 'confirm body 2', batchSize: 3);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected the first confirm wait to time out.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(AMQPTimeoutException::class, $exception->getPrevious());
            }

            self::assertCount(2, $this->getPendingBatchMessages($connection));

            $connection->publish(body: 'newer direct body');
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertSame([], $this->getPendingBatchMessages($connection));
    }

    public function testPublishReWaitsAPendingBatchConfirmBeforeAcceptingAnotherBatchMessage(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest(
            usesSingleChannel: true,
        );

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel1->expects(self::once())
            ->method('publish_batch');
        $amqpChannel1->expects(self::exactly(2))
            ->method('wait_for_pending_acks')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new AMQPTimeoutException('Confirm timeout')),
                true,
            );

        $amqpChannel2->expects(self::never())
            ->method('batch_basic_publish');
        $amqpChannel2->expects(self::never())
            ->method('publish_batch');

        $connection->publish(body: 'confirm body 1', batchSize: 3);
        $connection->publish(body: 'confirm body 2', batchSize: 3);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected the first confirm wait to time out.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(AMQPTimeoutException::class, $exception->getPrevious());
            }

            self::assertCount(2, $this->getPendingBatchMessages($connection));

            $connection->publish(body: 'confirm body 3', batchSize: 3);
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertCount(1, $this->getPendingBatchMessages($connection));
        self::assertSame('confirm body 3', $this->getPendingBatchMessages($connection)[0][0]->getBody());
    }

    public function testFlushRepublishesBatchWhenConnectionClosesWhileWaitingForPendingAcks(): void
    {
        [$connection, , $amqpChannel1, $amqpChannel2] = $this->createConnectionForBatchFlushRetryTest(
            requiresReconnect: true,
        );

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
            ->willThrowException(new AMQPConnectionClosedException('Connection closed while waiting for confirms'));

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

    public function testFlushDoesNotRetryWhenRetriesAreDisabled(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            retriesEnabled: false,
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

        $amqpChannel->expects(self::once())
            ->method('batch_basic_publish');
        $amqpChannel->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionClosedException('Broken pipe or closed connection'));

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->publish(body: 'retained body', batchSize: 2);

        try {
            $connection->flush();
            self::fail('Expected the flush to fail without retrying.');
        } catch (TransportException $exception) {
            self::assertSame('Broken pipe or closed connection', $exception->getMessage());
        }

        $pendingBatchMessages = $this->getPendingBatchMessages($connection);

        self::assertCount(1, $pendingBatchMessages);
        self::assertSame('retained body', $pendingBatchMessages[0][0]->getBody());
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

    public function testDirectPublishWaitsForARetainedBatchToFlushFirst(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        );

        $factory        = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel1   = $this->createMock(AMQPChannel::class);
        $amqpChannel2   = $this->createMock(AMQPChannel::class);
        $amqpChannel3   = $this->createMock(AMQPChannel::class);
        $publishStep    = 0;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->expects(self::exactly(3))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2, $amqpChannel3);

        foreach ([$amqpChannel1, $amqpChannel2, $amqpChannel3] as $amqpChannel) {
            $amqpChannel->expects(self::exactly(2))
                ->method('batch_basic_publish');
        }

        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionClosedException('Initial flush failed'));
        $amqpChannel1->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(false);
        $amqpChannel1->expects(self::never())
            ->method('basic_publish');

        $amqpChannel2->expects(self::once())
            ->method('publish_batch')
            ->willThrowException(new AMQPConnectionClosedException('Retained flush still failed'));
        $amqpChannel2->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(false);
        $amqpChannel2->expects(self::never())
            ->method('basic_publish');

        $amqpChannel3->expects(self::once())
            ->method('publish_batch')
            ->willReturnCallback(
                static function () use (&$publishStep): void {
                    self::assertSame(0, $publishStep);
                    $publishStep++;
                },
            );
        $amqpChannel3->expects(self::once())
            ->method('basic_publish')
            ->willReturnCallback(
                static function () use (&$publishStep): void {
                    self::assertSame(1, $publishStep);
                    $publishStep++;
                },
            );

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->publish(body: 'old body 1', batchSize: 3);
        $connection->publish(body: 'old body 2', batchSize: 3);

        $previousRetries        = Retry::$defaultRetries;
        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultRetries  = 0;
        Retry::$defaultWaitTime = 0;

        try {
            try {
                $connection->flush();
                self::fail('Expected the initial flush to fail.');
            } catch (TransportException) {
            }

            self::assertCount(2, $this->getPendingBatchMessages($connection));

            try {
                $connection->publish(body: 'new direct body');
                self::fail('Expected the retained batch flush to fail.');
            } catch (TransportException) {
            }

            // The newer direct message was not attempted while the older batch still failed.
            self::assertSame(0, $publishStep);
            self::assertCount(2, $this->getPendingBatchMessages($connection));

            $connection->publish(body: 'new direct body');
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertSame([], $this->getPendingBatchMessages($connection));
    }

    public function testRepeatedFlushesWhileBrokerIsBlockedDoNotKeepOpeningChannels(): void
    {
        $connectionConfig = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: 'exchange_name'),
        );

        $factory         = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection  = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel1    = $this->createMock(AMQPChannel::class);
        $amqpChannel2    = $this->createMock(AMQPChannel::class);
        $amqpChannel3    = $this->createMock(AMQPChannel::class);
        $blocked         = false;
        $channel2Flushes = 0;

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('isConnected')->willReturn(true);
        $amqpConnection->method('isBlocked')->willReturnCallback(
            static function () use (&$blocked): bool {
                return $blocked;
            },
        );
        $amqpConnection->expects(self::exactly(3))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($amqpChannel1, $amqpChannel2, $amqpChannel3);

        $amqpChannel1->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel1->expects(self::once())
            ->method('publish_batch')
            ->willReturnCallback(
                static function () use (&$blocked): never {
                    $blocked = true;

                    throw new AMQPConnectionBlockedException('Connection blocked');
                },
            );
        $amqpChannel1->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(false);
        $amqpChannel1->expects(self::once())
            ->method('close');

        $amqpChannel2->expects(self::exactly(4))
            ->method('batch_basic_publish');
        $amqpChannel2->expects(self::exactly(2))
            ->method('publish_batch')
            ->willReturnCallback(
                static function () use (&$blocked, &$channel2Flushes): void {
                    $channel2Flushes++;

                    if ($channel2Flushes === 2) {
                        $blocked = true;

                        throw new AMQPConnectionBlockedException('Connection blocked again');
                    }
                },
            );
        $amqpChannel2->expects(self::once())
            ->method('closeIfDisconnected')
            ->willReturn(false);
        $amqpChannel2->expects(self::once())
            ->method('close');

        $amqpChannel3->expects(self::exactly(2))
            ->method('batch_basic_publish');
        $amqpChannel3->expects(self::once())
            ->method('publish_batch');

        $connection = new Connection(
            retryFactory: new RetryFactory(),
            amqpConnectionFactory: $factory,
            connectionConfig: $connectionConfig,
        );

        $connection->publish(body: 'body 1', batchSize: 3);
        $connection->publish(body: 'body 2', batchSize: 3);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $connection->flush();
                self::fail('Expected the blocked flush to fail.');
            } catch (AMQPConnectionBlockedException) {
            }

            self::assertCount(2, $this->getPendingBatchMessages($connection));
        }

        $blocked = false;

        $connection->flush();

        self::assertSame([], $this->getPendingBatchMessages($connection));

        $connection->publish(body: 'body 3', batchSize: 3);
        $connection->publish(body: 'body 4', batchSize: 3);

        try {
            $connection->flush();
            self::fail('Expected the second blocked flush to fail.');
        } catch (AMQPConnectionBlockedException) {
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $connection->flush();
                self::fail('Expected the blocked flush to fail.');
            } catch (AMQPConnectionBlockedException) {
            }

            self::assertCount(2, $this->getPendingBatchMessages($connection));
        }

        $blocked = false;

        $connection->flush();

        self::assertSame([], $this->getPendingBatchMessages($connection));
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

    public function testCountMessagesInQueuesTreatsANullDeclareResultAsZero(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock();

        $amqpChannel->expects(self::once())
            ->method('queue_declare')
            ->willReturn(null);

        self::assertSame(0, $connection->countMessagesInQueues());
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

    public function testRetryDoesNotRetryWhenRetriesAreDisabled(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(retriesEnabled: false));
        $count      = 0;

        try {
            $connection->retry(static function () use (&$count): void {
                $count++;

                throw new AMQPConnectionClosedException('test');
            }, waitTime: 0)->run();

            self::fail('Expected the operation to fail without retrying.');
        } catch (TransportException $exception) {
            self::assertSame('test', $exception->getMessage());
        }

        self::assertSame(1, $count);
    }

    public function testRetryWithReconnectDoesNotRetryWhenRetriesAreDisabled(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(retriesEnabled: false));
        $count      = 0;

        try {
            $connection->retryWithReconnect(static function () use (&$count): void {
                $count++;

                throw new AMQPConnectionClosedException('test');
            }, waitTime: 0)->run();

            self::fail('Expected the operation to fail without retrying.');
        } catch (TransportException $exception) {
            self::assertSame('test', $exception->getMessage());
        }

        self::assertSame(1, $count);
    }

    public function testRetryUsesConfiguredLimit(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(retries: 1, retryWaitTime: 0));
        $count      = 0;

        try {
            $connection->retry(static function () use (&$count): void {
                $count++;

                throw new AMQPConnectionClosedException('test');
            })->run();

            self::fail('Expected the operation to fail after the configured retries.');
        } catch (TransportException $exception) {
            self::assertSame('test', $exception->getMessage());
        }

        self::assertSame(2, $count);
    }

    public function testRetryWithReconnectUsesConfiguredLimit(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(retries: 1, retryWaitTime: 0));
        $count      = 0;

        try {
            $connection->retryWithReconnect(static function () use (&$count): void {
                $count++;

                throw new AMQPConnectionClosedException('test');
            })->run();

            self::fail('Expected the operation to fail after the configured retries.');
        } catch (TransportException $exception) {
            self::assertSame('test', $exception->getMessage());
        }

        self::assertSame(2, $count);
    }

    public function testRetryUsesZeroConfiguredLimit(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(retries: 0, retryWaitTime: 0));
        $count      = 0;

        try {
            $connection->retry(static function () use (&$count): void {
                $count++;

                throw new AMQPConnectionClosedException('test');
            })->run();

            self::fail('Expected the operation to fail without retrying.');
        } catch (TransportException $exception) {
            self::assertSame('test', $exception->getMessage());
        }

        self::assertSame(1, $count);
    }

    public function testRetryHonorsExplicitWaitTimeOverConfig(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(retryWaitTime: 5000));

        $retry = $connection->retry(
            static function (): void {
            },
            waitTime: 0,
        );

        $waitTime = (new ReflectionProperty(Retry::class, 'waitTime'))->getValue($retry);

        self::assertSame(0, $waitTime);
    }

    public function testRetryUsesConfiguredWaitTimeWhenCallerOmitsIt(): void
    {
        $connection = $this->createConnectionWithStubs(new ConnectionConfig(retryWaitTime: 250));

        $retry = $connection->retry(static function (): void {
        });

        $waitTime = (new ReflectionProperty(Retry::class, 'waitTime'))->getValue($retry);

        self::assertSame(250, $waitTime);
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

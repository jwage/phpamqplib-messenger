<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionIdentity;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistry;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistryKey;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionReuse;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRole;
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
        $connectionConfig ??= $this->getDefaultConfig();
        $factory            = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection     = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel        = $this->createStub(AMQPChannel::class);
        $registry           = new AmqpConnectionRegistry($factory);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);

        return new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionRegistry: $registry,
            registryKey: $this->registryKeyForTests($connectionConfig),
            connectionConfig: $connectionConfig,
        );
    }

    /**
     * Creates a Connection with a mock AMQPChannel for verifying channel interactions.
     *
     * @return array{Connection, AMQPChannel&MockObject}
     */
    private function createConnectionWithChannelMock(ConnectionConfig|null $connectionConfig = null): array
    {
        $connectionConfig ??= $this->getDefaultConfig();
        $factory            = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection     = $this->createStub(AMQPStreamConnection::class);
        $amqpChannel        = $this->createMock(AMQPChannel::class);
        $registry           = new AmqpConnectionRegistry($factory);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);
        $amqpChannel->method('confirm_select');

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionRegistry: $registry,
            registryKey: $this->registryKeyForTests($connectionConfig),
            connectionConfig: $connectionConfig,
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
        $connectionConfig ??= $this->getDefaultConfig();
        $factory            = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection     = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel        = $this->createStub(AMQPChannel::class);
        $registry           = new AmqpConnectionRegistry($factory);

        $factory->method('create')->willReturn($amqpConnection);
        $amqpConnection->method('channel')->willReturn($amqpChannel);

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionRegistry: $registry,
            registryKey: $this->registryKeyForTests($connectionConfig),
            connectionConfig: $connectionConfig,
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
        $connectionConfig ??= $this->getDefaultConfig();
        $factory            = $this->createStub(AmqpConnectionFactory::class);
        $amqpConnection     = $this->createMock(AMQPStreamConnection::class);
        $amqpChannel        = $this->createMock(AMQPChannel::class);
        $registry           = new AmqpConnectionRegistry($factory);

        $factory->method('create')->willReturn($amqpConnection);

        $connection = new Connection(
            retryFactory: $this->retryFactory,
            amqpConnectionRegistry: $registry,
            registryKey: $this->registryKeyForTests($connectionConfig),
            connectionConfig: $connectionConfig,
        );

        return [$connection, $amqpConnection, $amqpChannel];
    }

    private function registryKeyForTests(
        ConnectionConfig $connectionConfig,
        AmqpConnectionReuse $reuse = AmqpConnectionReuse::ALL,
        AmqpConnectionRole $role = AmqpConnectionRole::MIXED,
        string|null $dedicatedInstanceId = null,
    ): AmqpConnectionRegistryKey {
        $identity = AmqpConnectionIdentity::fromConnectionConfig($connectionConfig);

        if ($reuse === AmqpConnectionReuse::NONE) {
            if ($dedicatedInstanceId === null || $dedicatedInstanceId === '') {
                throw new InvalidArgumentException('Tests must pass a non-empty dedicated id for connection_reuse=none.');
            }

            return AmqpConnectionRegistryKey::create(
                $identity,
                $reuse,
                $role,
                $dedicatedInstanceId,
            );
        }

        if ($dedicatedInstanceId !== null) {
            throw new InvalidArgumentException('Tests must pass null dedicated id when connection_reuse is not none.');
        }

        return AmqpConnectionRegistryKey::create(
            $identity,
            $reuse,
            $role,
            null,
        );
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

        $amqpConnection->expects(self::never())
            ->method('close');

        $connection->channel();

        $connection->close();
    }

    public function testReconnect(): void
    {
        [$connection, $amqpConnection] = $this->createConnectionWithConnectionMock();

        $connection->channel();

        $amqpConnection->expects(self::once())
            ->method('close');

        $connection->reconnect();
    }

    public function testReconnectInvalidatesStaleChannelAcrossConnections(): void
    {
        $connectionConfig     = $this->getDefaultConfig();
        $registryKey          = $this->registryKeyForTests($connectionConfig);
        $factory              = $this->createMock(AmqpConnectionFactory::class);
        $registry             = new AmqpConnectionRegistry($factory);
        $firstAmqpConnection  = $this->createMock(AMQPStreamConnection::class);
        $secondAmqpConnection = $this->createMock(AMQPStreamConnection::class);
        $firstChannelA        = $this->createStub(AMQPChannel::class);
        $firstChannelB        = $this->createStub(AMQPChannel::class);
        $secondChannel        = $this->createStub(AMQPChannel::class);

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($firstAmqpConnection, $secondAmqpConnection);

        $firstAmqpConnection->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($firstChannelA, $firstChannelB);
        $secondAmqpConnection->expects(self::once())
            ->method('channel')
            ->willReturn($secondChannel);
        $firstAmqpConnection->expects(self::once())
            ->method('close');

        $connectionA = new Connection($this->retryFactory, $registry, $registryKey, $connectionConfig);
        $connectionB = new Connection($this->retryFactory, $registry, $registryKey, $connectionConfig);

        self::assertSame($firstChannelA, $connectionA->channel());
        self::assertSame($firstChannelB, $connectionB->channel());

        $connectionA->reconnect();

        self::assertSame($secondChannel, $connectionB->channel());
    }

    public function testPublishFailureReconnectsAndRetriesPublish(): void
    {
        $connectionConfig     = new ConnectionConfig(
            autoSetup: false,
            confirmEnabled: false,
            exchange: new ExchangeConfig(name: ''),
            queues: [],
        );
        $registryKey          = $this->registryKeyForTests($connectionConfig);
        $factory              = $this->createMock(AmqpConnectionFactory::class);
        $registry             = new AmqpConnectionRegistry($factory);
        $firstAmqpConnection  = $this->createMock(AMQPStreamConnection::class);
        $secondAmqpConnection = $this->createMock(AMQPStreamConnection::class);
        $firstChannel         = $this->createMock(AMQPChannel::class);
        $secondChannel        = $this->createMock(AMQPChannel::class);

        $factory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($firstAmqpConnection, $secondAmqpConnection);

        $firstAmqpConnection->method('channel')->willReturn($firstChannel);
        $secondAmqpConnection->method('channel')->willReturn($secondChannel);

        $firstChannel->expects(self::once())
            ->method('basic_publish')
            ->willThrowException(new AMQPConnectionClosedException('failed publish'));
        $secondChannel->expects(self::once())
            ->method('basic_publish');
        $firstAmqpConnection->expects(self::once())
            ->method('close');

        $connection = new Connection($this->retryFactory, $registry, $registryKey, $connectionConfig);
        $connection->publish('test');
    }

    public function testReconnectDoesNotInvalidateOtherRegistryKey(): void
    {
        $connectionConfig = $this->getDefaultConfig();
        $factory          = $this->createMock(AmqpConnectionFactory::class);
        $registry         = new AmqpConnectionRegistry($factory);
        $streamA          = $this->createMock(AMQPStreamConnection::class);
        $streamB          = $this->createMock(AMQPStreamConnection::class);
        $streamA2         = $this->createMock(AMQPStreamConnection::class);
        $channelA1        = $this->createStub(AMQPChannel::class);
        $channelB         = $this->createStub(AMQPChannel::class);

        $factory->expects(self::exactly(3))
            ->method('create')
            ->willReturnOnConsecutiveCalls($streamA, $streamB, $streamA2);

        $keyProducer = $this->registryKeyForTests(
            $connectionConfig,
            AmqpConnectionReuse::PRODUCER_CONSUMER,
            AmqpConnectionRole::PRODUCER,
        );
        $keyConsumer = $this->registryKeyForTests(
            $connectionConfig,
            AmqpConnectionReuse::PRODUCER_CONSUMER,
            AmqpConnectionRole::CONSUMER,
        );

        $streamA->method('channel')->willReturn($channelA1);
        $streamB->method('channel')->willReturn($channelB);
        $streamA->expects(self::once())
            ->method('close');

        $connectionProducer = new Connection($this->retryFactory, $registry, $keyProducer, $connectionConfig);
        $connectionConsumer = new Connection($this->retryFactory, $registry, $keyConsumer, $connectionConfig);

        self::assertSame($channelA1, $connectionProducer->channel());
        self::assertSame($channelB, $connectionConsumer->channel());

        $connectionProducer->reconnect();

        self::assertSame($channelB, $connectionConsumer->channel());
    }

    public function testSameRegistryKeyStillUsesSeparateAmqpChannelsPerWrapper(): void
    {
        $connectionConfig = $this->getDefaultConfig();
        $registryKey      = $this->registryKeyForTests($connectionConfig);
        $factory          = $this->createMock(AmqpConnectionFactory::class);
        $registry         = new AmqpConnectionRegistry($factory);
        $stream           = $this->createMock(AMQPStreamConnection::class);
        $channelA         = $this->createStub(AMQPChannel::class);
        $channelB         = $this->createStub(AMQPChannel::class);

        $factory->expects(self::once())
            ->method('create')
            ->willReturn($stream);

        $stream->expects(self::exactly(2))
            ->method('channel')
            ->willReturnOnConsecutiveCalls($channelA, $channelB);

        $connectionA = new Connection($this->retryFactory, $registry, $registryKey, $connectionConfig);
        $connectionB = new Connection($this->retryFactory, $registry, $registryKey, $connectionConfig);

        self::assertNotSame($connectionA->channel(), $connectionB->channel());
    }

    public function testReconnectClearsPendingBatchState(): void
    {
        [$connection, $amqpChannel] = $this->createConnectionWithChannelMock(
            new ConnectionConfig(
                autoSetup: false,
                confirmEnabled: false,
                exchange: new ExchangeConfig(name: 'ex'),
                queues: [],
            ),
        );

        $amqpChannel->expects(self::exactly(3))
            ->method('batch_basic_publish');
        $amqpChannel->expects(self::once())
            ->method('publish_batch');

        $connection->publish('a', batchSize: 2);
        $connection->reconnect();
        $connection->publish('b', batchSize: 2);
        $connection->publish('c', batchSize: 2);
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->retryFactory = new RetryFactory();

        $this->connection = $this->createConnectionWithStubs();
    }
}

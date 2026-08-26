<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Closure;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConsumer;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\TransportException;
use Traversable;

use function array_shift;
use function iterator_to_array;

class AmqpConsumerTest extends TestCase
{
    private LoggerInterface $logger;

    private RetryFactory $retryFactory;

    private AmqpConnectionFactory $amqpConnectionFactory;

    private ConnectionConfig $connectionConfig;

    public function testConsume(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 20,
                a_global: false,
            );

        $channel->expects(self::once())
            ->method('basic_consume')
            ->with(
                queue: 'test_queue',
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: self::isInstanceOf(Closure::class),
            )
            ->willReturn('consumer_tag');

        $channel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);

        $channel->expects(self::once())
            ->method('wait')
            ->with(
                allowed_methods: null,
                non_blocking: false,
                timeout: 2,
            )
            ->will($this->throwException(new AMQPTimeoutException()));

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));

        $message = $this->createStub(AMQPMessage::class);

        $consumer->callback($message);

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(1, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumeKeepsPollingUntilTheWaitTimesOut(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos');
        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');
        $channel->expects(self::exactly(2))
            ->method('is_consuming')
            ->willReturn(true);
        $channel->expects(self::exactly(2))
            ->method('wait')
            ->willReturnOnConsecutiveCalls(
                null,
                $this->throwException(new AMQPTimeoutException()),
            );

        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');
        iterator_to_array($amqpEnvelopes);
    }

    public function testConsumeReRegistersAfterTheConsumerChannelIsDiscarded(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $state   = new class {
            public bool $invalidate = false;

            public AmqpConsumer|null $consumer = null;
        };

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturnCallback(static function () use ($state, $channel): AMQPChannel {
                if ($state->invalidate) {
                    self::assertInstanceOf(AmqpConsumer::class, $state->consumer);
                    $state->consumer->invalidate();
                    $state->invalidate = false;
                }

                return $channel;
            });

        $state->consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::exactly(2))
            ->method('basic_consume')
            ->willReturn('consumer_tag');
        $channel->method('is_consuming')
            ->willReturn(true);
        $channel->method('wait')
            ->will($this->throwException(new AMQPTimeoutException()));

        /** @var Traversable<AmqpEnvelope> $first */
        $first = $state->consumer->consume('test_queue');
        iterator_to_array($first);

        $state->invalidate = true;

        /** @var Traversable<AmqpEnvelope> $second */
        $second = $state->consumer->consume('test_queue');
        iterator_to_array($second);
    }

    public function testConsumeWithUnexpectedAMQPException(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnection(onlyMethods: ['consumerChannel', 'getQueueNames', 'close']);
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $logger = $this->createMock(LoggerInterface::class);

        $consumer = $this->getTestConsumer(connection: $connection, logger: $logger);

        $channel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 20,
                a_global: false,
            );

        $channel->expects(self::once())
            ->method('basic_consume')
            ->with(
                queue: 'test_queue',
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: self::isInstanceOf(Closure::class),
            )
            ->willReturn('consumer_tag');

        $channel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);

        $exception = new AMQPProtocolChannelException(1, 'Test', []);

        $channel->expects(self::once())
            ->method('wait')
            ->with(
                allowed_methods: null,
                non_blocking: false,
                timeout: 2,
            )
            ->will($this->throwException($exception));

        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'AMQP exception occurred while waiting for messages: {message}',
                ['message' => 'Test', 'exception' => $exception],
            );

        $connection->expects(self::once())
            ->method('close');

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumeWithUnexpectedAMQPExceptionDoesNotRequireALogger(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnection(onlyMethods: ['consumerChannel', 'getQueueNames', 'close']);
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $consumer = new AmqpConsumer($connection, $this->connectionConfig, null);

        $channel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 20,
                a_global: false,
            );

        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');

        $channel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);

        $channel->expects(self::once())
            ->method('wait')
            ->will($this->throwException(new AMQPProtocolChannelException(1, 'Test', [])));

        $connection->expects(self::once())
            ->method('close');

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testInvalidateDropsTheConsumerTagAndBufferedEnvelopes(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::exactly(2))
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 20,
                a_global: false,
            );

        $channel->expects(self::exactly(2))
            ->method('basic_consume')
            ->with(
                queue: 'test_queue',
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: self::isInstanceOf(Closure::class),
            )
            ->willReturn('consumer_tag');

        $channel->expects(self::exactly(2))
            ->method('is_consuming')
            ->willReturn(true);

        $channel->expects(self::exactly(2))
            ->method('wait')
            ->will($this->throwException(new AMQPTimeoutException()));

        /** @var Traversable<AmqpEnvelope> $consumed */
        $consumed = $consumer->consume('test_queue');
        iterator_to_array($consumed);

        $consumer->callback($this->createStub(AMQPMessage::class));
        $consumer->invalidate();

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testHasBufferedEnvelopesTracksCallbackAndInvalidate(): void
    {
        $consumer = $this->getTestConsumer();

        self::assertFalse($consumer->hasBufferedEnvelopes());

        $consumer->callback($this->createStub(AMQPMessage::class));

        self::assertTrue($consumer->hasBufferedEnvelopes());

        $consumer->invalidate();

        self::assertFalse($consumer->hasBufferedEnvelopes());
    }

    public function testConsumeWithWaitTimeoutSetToNull(): void
    {
        $connectionConfig = ConnectionConfig::fromArray([
            'queues' => [
                'test_queue' => [
                    'prefetch_count' => 20,
                    'wait_timeout' => null,
                ],
            ],
        ]);

        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub(connectionConfig: $connectionConfig);
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $consumer = $this->getTestConsumer(connectionConfig: $connectionConfig, connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 20,
                a_global: false,
            );

        $channel->expects(self::once())
            ->method('basic_consume')
            ->with(
                queue: 'test_queue',
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: self::isInstanceOf(Closure::class),
            )
            ->willReturn('consumer_tag');

        $channel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);

        $channel->expects(self::once())
            ->method('wait')
            ->with(
                allowed_methods: null,
                non_blocking: false,
                timeout: 1,
            )
            ->will($this->throwException(new AMQPTimeoutException()));

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));

        $message = $this->createStub(AMQPMessage::class);

        $consumer->callback($message);

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(1, iterator_to_array($amqpEnvelopes));
    }

    /**
     * Requires fetchSize: the drain-by-shifting path (fetchSize != null) shifts items off the
     * buffer one-by-one, so only already-yielded items are removed when the caller breaks early.
     * See testConsumeWithoutFetchSizeLosesUnyieldedMessagesOnEarlyBreak for the legacy limitation.
     */
    public function testConsumePreservesUnyieldedMessagesWhenCallerBreaksEarly(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos');

        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');

        $channel->expects(self::never())
            ->method('wait');

        $message1 = $this->createStub(AMQPMessage::class);
        $message2 = $this->createStub(AMQPMessage::class);
        $message3 = $this->createStub(AMQPMessage::class);

        $consumer->callback($message1);
        $consumer->callback($message2);
        $consumer->callback($message3);

        $received = [];

        foreach ($consumer->consume('test_queue', 1) as $amqpEnvelope) {
            $received[] = $amqpEnvelope;

            break;
        }

        self::assertCount(1, $received);

        /** @var Traversable<AmqpEnvelope> $remainingEnvelopes */
        $remainingEnvelopes = $consumer->consume('test_queue', 1);
        $remaining          = iterator_to_array($remainingEnvelopes, false);

        self::assertCount(2, $remaining);
    }

    /**
     * Documents a known limitation of the legacy consume path (fetchSize === null):
     * the buffer is snapshotted and cleared upfront before yielding, so any messages
     * not yet yielded when the caller breaks early are silently lost.
     * Use fetchSize to avoid this — see testConsumePreservesUnyieldedMessagesWhenCallerBreaksEarly.
     */
    public function testConsumeWithoutFetchSizeLosesUnyieldedMessagesOnEarlyBreak(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos');

        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');

        $channel->expects(self::never())
            ->method('wait');

        $message1 = $this->createStub(AMQPMessage::class);
        $message2 = $this->createStub(AMQPMessage::class);
        $message3 = $this->createStub(AMQPMessage::class);

        $consumer->callback($message1);
        $consumer->callback($message2);
        $consumer->callback($message3);

        $received = [];

        // No fetchSize → legacy snapshot path; buffer is cleared before iteration starts
        foreach ($consumer->consume('test_queue') as $amqpEnvelope) {
            $received[] = $amqpEnvelope;

            break;
        }

        self::assertCount(1, $received);

        // The 2 unyielded messages are dropped — this is the known legacy limitation
        /** @var Traversable<AmqpEnvelope> $remainingEnvelopes */
        $remainingEnvelopes = $consumer->consume('test_queue');
        $remaining          = iterator_to_array($remainingEnvelopes, false);

        self::assertCount(0, $remaining);
    }

    public function testConsumeWithFetchSizeGreaterThanPrefetchCountOverridesPrefetch(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 50,
                a_global: false,
            );

        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');

        $channel->method('is_consuming')
            ->willReturn(true);

        $channel->method('wait')
            ->will($this->throwException(new AMQPTimeoutException()));

        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue', 50);
        iterator_to_array($amqpEnvelopes);
    }

    public function testConsumeWithFetchSizeSmallerThanPrefetchCountDoesNotOverride(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 20,
                a_global: false,
            );

        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');

        $channel->method('is_consuming')
            ->willReturn(true);

        $channel->method('wait')
            ->will($this->throwException(new AMQPTimeoutException()));

        // fetchSize=5 < prefetchCount=20 → effective prefetch stays 20
        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue', 5);
        iterator_to_array($amqpEnvelopes);
    }

    public function testConsumeUpdatesQosWhenFetchSizeIncreasesAbovePrefetchCount(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);

        $consumer = $this->getTestConsumer(connection: $connection);

        $expectedPrefetchCounts = [20, 50];
        $channel->expects(self::exactly(2))
            ->method('basic_qos')
            ->willReturnCallback(static function (int $prefetchSize, int $prefetchCount, bool $aGlobal) use (&$expectedPrefetchCounts): void {
                self::assertSame(array_shift($expectedPrefetchCounts), $prefetchCount);
            });

        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');

        $channel->expects(self::exactly(2))
            ->method('is_consuming')
            ->willReturn(true);

        $channel->expects(self::exactly(2))
            ->method('wait')
            ->will($this->throwException(new AMQPTimeoutException()));

        // First consume: no fetchSize → prefetch stays at config value (20)
        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');
        iterator_to_array($amqpEnvelopes);

        // Second consume: fetchSize=50 > prefetchCount=20 → QoS updated to 50
        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue', 50);
        iterator_to_array($amqpEnvelopes);
    }

    public function testStopConsumer(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['test_queue']);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 20,
                a_global: false,
            );

        $channel->expects(self::once())
            ->method('basic_consume')
            ->with(
                queue: 'test_queue',
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: self::isInstanceOf(Closure::class),
            )
            ->willReturn('consumer_tag');

        $channel->expects(self::once())
            ->method('is_consuming')
            ->willReturn(true);

        $channel->expects(self::once())
            ->method('wait')
            ->with(
                allowed_methods: null,
                non_blocking: false,
                timeout: 2,
            )
            ->will($this->throwException(new AMQPTimeoutException()));

        $channel->expects(self::once())
            ->method('basic_cancel')
            ->with(
                consumer_tag: 'consumer_tag',
            );

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));

        $consumer->stop();
    }

    public function testConsumeWithExternalWaitDrainsWithoutBlocking(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnection(onlyMethods: [
            'consumerChannel',
            'getQueueNames',
            'close',
            'isExternalWaitEnabled',
            'drainConsumerChannel',
        ]);
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('isExternalWaitEnabled')
            ->willReturn(true);
        $connection->expects(self::once())
            ->method('drainConsumerChannel');

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos');
        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');
        $channel->expects(self::never())
            ->method('wait');
        $channel->expects(self::never())
            ->method('is_consuming');
        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumeWithExternalWaitYieldsBufferedMessagesWithoutDraining(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnection(onlyMethods: [
            'consumerChannel',
            'getQueueNames',
            'close',
            'isExternalWaitEnabled',
            'drainConsumerChannel',
        ]);
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('isExternalWaitEnabled')
            ->willReturn(true);
        $connection->expects(self::never())
            ->method('drainConsumerChannel');

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos');
        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');

        $consumer->callback($this->createStub(AMQPMessage::class));

        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(1, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumeWithWaitCoordinatorWaitsWithoutBlockingTheChannel(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnection(onlyMethods: [
            'consumerChannel',
            'getQueueNames',
            'close',
            'isExternalWaitEnabled',
            'isRegisteredWithWaitCoordinator',
            'waitForDeliveries',
            'getWaitTimeout',
        ]);
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('isExternalWaitEnabled')
            ->willReturn(false);
        $connection->method('isRegisteredWithWaitCoordinator')
            ->willReturn(true);
        $connection->method('getWaitTimeout')
            ->willReturn(1.5);
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(1.5);

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos');
        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');
        $channel->expects(self::never())
            ->method('wait');
        $channel->expects(self::never())
            ->method('is_consuming');

        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumeWithWaitCoordinatorYieldsBufferWithoutWaiting(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnection(onlyMethods: [
            'consumerChannel',
            'getQueueNames',
            'close',
            'isExternalWaitEnabled',
            'isRegisteredWithWaitCoordinator',
            'waitForDeliveries',
            'getWaitTimeout',
        ]);
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('isExternalWaitEnabled')
            ->willReturn(false);
        $connection->method('isRegisteredWithWaitCoordinator')
            ->willReturn(true);
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $consumer = $this->getTestConsumer(connection: $connection);

        $channel->expects(self::once())
            ->method('basic_qos');
        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');

        $consumer->callback($this->createStub(AMQPMessage::class));

        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(1, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumeReturnsEmptyWhenStartingTheConsumerFails(): void
    {
        $exception = new TransportException('Broken pipe or closed connection');

        $connection = $this->getTestConnection(onlyMethods: [
            'consumerChannel',
            'getQueueNames',
            'close',
            'isRegisteredWithWaitCoordinator',
        ]);
        $connection->expects(self::once())
            ->method('consumerChannel')
            ->willThrowException($exception);
        $connection->expects(self::once())
            ->method('isRegisteredWithWaitCoordinator')
            ->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'AMQP exception occurred while starting consumer: {message}',
                ['message' => 'Broken pipe or closed connection', 'exception' => $exception],
            );

        $consumer = $this->getTestConsumer(connection: $connection, logger: $logger);

        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumeReturnsEmptyWhenStartingTheConsumerFailsWithoutALogger(): void
    {
        $connection = $this->getTestConnection(onlyMethods: [
            'consumerChannel',
            'getQueueNames',
            'close',
            'isRegisteredWithWaitCoordinator',
        ]);
        $connection->expects(self::once())
            ->method('consumerChannel')
            ->willThrowException(new TransportException('Broken pipe or closed connection'));
        $connection->expects(self::once())
            ->method('isRegisteredWithWaitCoordinator')
            ->willReturn(true);

        $consumer = new AmqpConsumer($connection, $this->connectionConfig, null);

        /** @var Traversable<AmqpEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));
    }

    public function testConsumePropagatesStartFailureOutsideAWorker(): void
    {
        $connection = $this->getTestConnectionStub();
        $connection->method('consumerChannel')
            ->willThrowException(new TransportException('Broken pipe or closed connection'));

        $consumer = $this->getTestConsumer(connection: $connection);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Broken pipe or closed connection');

        /** @var Traversable<mixed, AmqpEnvelope> $envelopes */
        $envelopes = $consumer->consume('test_queue');
        iterator_to_array($envelopes);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createStub(LoggerInterface::class);

        $this->retryFactory = new RetryFactory($this->logger);

        $this->amqpConnectionFactory = $this->createStub(AmqpConnectionFactory::class);

        $this->connectionConfig = new ConnectionConfig(
            queues: [
                'test_queue' => new QueueConfig(
                    name: 'test_queue',
                    prefetchCount: 20,
                    waitTimeout: 2,
                ),
            ],
        );
    }

    private function getTestConsumer(
        ConnectionConfig|null $connectionConfig = null,
        Connection|null $connection = null,
        LoggerInterface|null $logger = null,
    ): AmqpConsumer {
        return new AmqpConsumer(
            $connection ?? $this->getTestConnectionStub(connectionConfig: $connectionConfig),
            $connectionConfig ?? $this->connectionConfig,
            $logger ?? $this->logger,
        );
    }

    private function getTestConnectionStub(ConnectionConfig|null $connectionConfig = null): Connection&Stub
    {
        return self::getStubBuilder(Connection::class)
            ->onlyMethods(['consumerChannel', 'getQueueNames', 'close'])
            ->setConstructorArgs([
                $this->retryFactory,
                $this->amqpConnectionFactory,
                $connectionConfig ?? $this->connectionConfig,
                $this->logger,
            ])
            ->getStub();
    }

    /** @param list<non-empty-string> $onlyMethods */
    private function getTestConnection(
        ConnectionConfig|null $connectionConfig = null,
        array $onlyMethods = ['consumerChannel', 'getQueueNames', 'close'],
    ): Connection&MockObject {
        return $this->getMockBuilder(Connection::class)
            ->onlyMethods($onlyMethods)
            ->setConstructorArgs([
                $this->retryFactory,
                $this->amqpConnectionFactory,
                $connectionConfig ?? $this->connectionConfig,
                $this->logger,
            ])
            ->getMock();
    }
}

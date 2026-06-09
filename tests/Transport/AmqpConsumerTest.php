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
use Traversable;

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
        $connection->method('channel')
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

        $channel->expects(self::exactly(2))
            ->method('is_consuming')
            ->willReturn(true);

        $channel->expects(self::exactly(2))
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

    public function testConsumeWithUnexpectedAMQPException(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnection(onlyMethods: ['channel', 'getQueueNames', 'close']);
        $connection->method('channel')
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
        $connection->method('channel')
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

        $channel->expects(self::exactly(2))
            ->method('is_consuming')
            ->willReturn(true);

        $channel->expects(self::exactly(2))
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

    public function testConsumePreservesUnyieldedMessagesWhenCallerBreaksEarly(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('channel')
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
            ->will($this->throwException(new AMQPTimeoutException()));

        $message1 = $this->createStub(AMQPMessage::class);
        $message2 = $this->createStub(AMQPMessage::class);
        $message3 = $this->createStub(AMQPMessage::class);

        $consumer->callback($message1);
        $consumer->callback($message2);
        $consumer->callback($message3);

        $received = [];

        foreach ($consumer->consume('test_queue') as $amqpEnvelope) {
            $received[] = $amqpEnvelope;

            break;
        }

        self::assertCount(1, $received);

        $remaining = iterator_to_array($consumer->consume('test_queue'), false);

        self::assertCount(2, $remaining);
    }

    public function testStopConsumer(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $connection = $this->getTestConnectionStub();
        $connection->method('channel')
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
            ->onlyMethods(['channel', 'getQueueNames', 'close'])
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
        array $onlyMethods = ['channel', 'getQueueNames', 'close'],
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

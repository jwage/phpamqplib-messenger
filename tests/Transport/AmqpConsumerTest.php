<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Closure;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConsumer;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use Traversable;

use function iterator_to_array;

class AmqpConsumerTest extends TestCase
{
    /** @var MockObject&Connection */
    private Connection $connection;

    private ConnectionConfig $connectionConfig;

    private AmqpConsumer $consumer;

    public function testConsume(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $this->connection->expects(self::any())
            ->method('channel')
            ->willReturn($channel);

        $this->connection->expects(self::any())
            ->method('getQueueNames')
            ->willReturn(['test_queue']);

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
            );

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
        $amqpEnvelopes = $this->consumer->consume('test_queue');

        self::assertCount(0, iterator_to_array($amqpEnvelopes));

        $message = $this->createMock(AMQPMessage::class);

        $this->consumer->callback($message);

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $this->consumer->consume('test_queue');

        self::assertCount(1, iterator_to_array($amqpEnvelopes));
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

        $consumer = $this->getTestConsumer($connectionConfig);

        $channel = $this->createMock(AMQPChannel::class);

        $this->connection->expects(self::any())
            ->method('channel')
            ->willReturn($channel);

        $this->connection->expects(self::any())
            ->method('getQueueNames')
            ->willReturn(['test_queue']);

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
            );

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

        $message = $this->createMock(AMQPMessage::class);

        $consumer->callback($message);

        /** @var Traversable<AMQPEnvelope> $amqpEnvelopes */
        $amqpEnvelopes = $consumer->consume('test_queue');

        self::assertCount(1, iterator_to_array($amqpEnvelopes));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(Connection::class);

        $this->connectionConfig = new ConnectionConfig(
            queues: [
                'test_queue' => new QueueConfig(
                    name: 'test_queue',
                    prefetchCount: 20,
                    waitTimeout: 2,
                ),
            ],
        );

        $this->consumer = $this->getTestConsumer();
    }

    private function getTestConsumer(ConnectionConfig|null $connectionConfig = null): AmqpConsumer
    {
        return new AmqpConsumer($this->connection, $connectionConfig ?? $this->connectionConfig);
    }
}

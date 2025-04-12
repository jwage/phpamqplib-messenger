<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Closure;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPConsumer;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;

class AMQPConsumerTest extends TestCase
{
    /** @var MockObject&Connection */
    private Connection $connection;

    private AMQPConsumer $consumer;

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
                prefetch_count: 1,
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

        $channel->expects(self::once())
            ->method('wait')
            ->with(
                allowed_methods: null,
                non_blocking: false,
                timeout: 2,
            )
            ->willThrowException(new AMQPTimeoutException());

        $amqpEnvelope = $this->consumer->get('test_queue', 2);

        self::assertNull($amqpEnvelope);

        $message = $this->createMock(AMQPMessage::class);

        $this->consumer->callback($message);

        $amqpEnvelope = $this->consumer->get('test_queue', 2);

        self::assertNotNull($amqpEnvelope);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(Connection::class);

        $this->consumer = new AMQPConsumer($this->connection);
    }
}

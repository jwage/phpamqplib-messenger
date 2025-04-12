<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPReceiver;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function serialize;

class AMQPReceiverTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private RetryFactory $retryFactory;

    /** @var Connection&MockObject */
    private Connection $connection;

    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    private AMQPReceiver $receiver;

    public function testGet(): void
    {
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage  = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpEnvelope = new AMQPEnvelope($amqpMessage);

        $this->connection->expects(self::any())
            ->method('getQueueNames')
            ->willReturn(['queue_name']);

        $this->connection->expects(self::once())
            ->method('get')
            ->willReturn($amqpEnvelope);

        $this->serializer->expects(self::once())
            ->method('decode')
            ->with(['body' => 'O:8:"stdClass":0:{}', 'headers' => []])
            ->willReturn($envelope);

        $iterable = $this->receiver->get();

        $envelopes = [];

        foreach ($iterable as $envelope) {
            $envelopes[] = $envelope;
        }

        self::assertCount(1, $envelopes);

        $envelope1 = $envelopes[0];

        $transportMessageIdStamp1 = $envelope1->last(TransportMessageIdStamp::class);

        self::assertInstanceOf(TransportMessageIdStamp::class, $transportMessageIdStamp1);
        self::assertSame('1', $transportMessageIdStamp1->getId());

        $amqpReceivedStamp1 = $envelope1->last(AMQPReceivedStamp::class);

        self::assertInstanceOf(AMQPReceivedStamp::class, $amqpReceivedStamp1);
        self::assertSame($amqpEnvelope, $amqpReceivedStamp1->getAMQPEnvelope());
        self::assertSame('queue_name', $amqpReceivedStamp1->getQueueName());
    }

    public function testAck(): void
    {
        $amqpEnvelope = $this->createMock(AMQPEnvelope::class);

        $amqpEnvelope->expects(self::once())
            ->method('ack');

        $stamp = new AMQPReceivedStamp($amqpEnvelope, 'queue_name');

        $envelope = new Envelope(new stdClass(), [$stamp]);

        $this->receiver->ack($envelope);
    }

    public function testReject(): void
    {
        $amqpEnvelope = $this->createMock(AMQPEnvelope::class);

        $amqpEnvelope->expects(self::once())
            ->method('nack');

        $stamp = new AMQPReceivedStamp($amqpEnvelope, 'queue_name');

        $envelope = new Envelope(new stdClass(), [$stamp]);

        $this->receiver->reject($envelope);
    }

    public function testGetMessageCount(): void
    {
        $this->connection->expects(self::once())
            ->method('countMessagesInQueues')
            ->willReturn(10);

        self::assertSame(10, $this->receiver->getMessageCount());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->retryFactory = new RetryFactory($this->logger);

        $this->connection = $this->createMock(Connection::class);

        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->receiver = new AMQPReceiver(
            $this->retryFactory,
            $this->connection,
            $this->serializer,
        );
    }
}

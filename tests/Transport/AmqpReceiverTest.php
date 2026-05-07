<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionIdentity;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionRegistry;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceiver;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function serialize;

class AmqpReceiverTest extends TestCase
{
    private LoggerInterface $logger;

    private RetryFactory $retryFactory;

    private AmqpConnectionFactory $amqpConnectionFactory;

    private AmqpConnectionRegistry $amqpConnectionRegistry;

    private ConnectionConfig $connectionConfig;

    public function testGet(): void
    {
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage  = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpEnvelope = new AmqpEnvelope($amqpMessage);

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name']);

        $connection->expects(self::once())
            ->method('consume')
            ->willReturn([$amqpEnvelope]);

        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects(self::once())
            ->method('decode')
            ->with(['body' => 'O:8:"stdClass":0:{}', 'headers' => []])
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);
        $iterable = $receiver->get();

        $envelopes = [];

        foreach ($iterable as $envelope) {
            $envelopes[] = $envelope;
        }

        self::assertCount(1, $envelopes);

        $envelope1 = $envelopes[0];

        $transportMessageIdStamp1 = $envelope1->last(TransportMessageIdStamp::class);

        self::assertInstanceOf(TransportMessageIdStamp::class, $transportMessageIdStamp1);
        self::assertSame('1', $transportMessageIdStamp1->getId());

        $amqpReceivedStamp1 = $envelope1->last(AmqpReceivedStamp::class);

        self::assertInstanceOf(AmqpReceivedStamp::class, $amqpReceivedStamp1);
        self::assertSame($amqpEnvelope, $amqpReceivedStamp1->getAMQPEnvelope());
        self::assertSame('queue_name', $amqpReceivedStamp1->getQueueName());
    }

    public function testAck(): void
    {
        $amqpEnvelope = $this->createMock(AmqpEnvelope::class);

        $amqpEnvelope->expects(self::once())
            ->method('ack');

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'queue_name');

        $envelope = new Envelope(new stdClass(), [$stamp]);

        $receiver = new AmqpReceiver(
            $this->getTestConnectionStub(),
            $this->createStub(SerializerInterface::class),
        );

        $receiver->ack($envelope);
    }

    public function testReject(): void
    {
        $amqpEnvelope = $this->createMock(AmqpEnvelope::class);

        $amqpEnvelope->expects(self::once())
            ->method('nack');

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'queue_name');

        $envelope = new Envelope(new stdClass(), [$stamp]);

        $receiver = new AmqpReceiver(
            $this->getTestConnectionStub(),
            $this->createStub(SerializerInterface::class),
        );

        $receiver->reject($envelope);
    }

    public function testGetMessageCount(): void
    {
        $connection = $this->getTestConnection();

        $connection->expects(self::once())
            ->method('countMessagesInQueues')
            ->willReturn(10);

        $receiver = new AmqpReceiver(
            $connection,
            $this->createStub(SerializerInterface::class),
        );

        self::assertSame(10, $receiver->getMessageCount());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createStub(LoggerInterface::class);

        $this->retryFactory = new RetryFactory($this->logger);

        $this->amqpConnectionFactory  = $this->createStub(AmqpConnectionFactory::class);
        $this->amqpConnectionRegistry = new AmqpConnectionRegistry($this->amqpConnectionFactory);

        $this->connectionConfig = new ConnectionConfig();
    }

    private function getTestConnectionStub(): Connection&MockObject
    {
        return $this->getMockBuilder(Connection::class)
            ->onlyMethods(['getQueueNames', 'consume', 'countMessagesInQueues'])
            ->setConstructorArgs([
                $this->retryFactory,
                $this->amqpConnectionRegistry,
                AmqpConnectionIdentity::fromConnectionConfig($this->connectionConfig),
                $this->connectionConfig,
                $this->logger,
            ])
            ->getMock();
    }

    private function getTestConnection(): Connection&MockObject
    {
        return $this->getMockBuilder(Connection::class)
            ->onlyMethods(['getQueueNames', 'consume', 'countMessagesInQueues'])
            ->setConstructorArgs([
                $this->retryFactory,
                $this->amqpConnectionRegistry,
                AmqpConnectionIdentity::fromConnectionConfig($this->connectionConfig),
                $this->connectionConfig,
                $this->logger,
            ])
            ->getMock();
    }
}

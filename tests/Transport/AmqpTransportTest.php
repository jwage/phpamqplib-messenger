<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceiver;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpSender;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpTransportTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $connection;

    /** @var AMQPReceiver&MockObject */
    private AmqpReceiver $receiver;

    /** @var AMQPSender&MockObject */
    private AmqpSender $sender;

    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    private AmqpTransport $transport;

    public function testGetConnection(): void
    {
        self::assertSame($this->connection, $this->transport->getConnection());
    }

    public function testGet(): void
    {
        $envelope1 = new Envelope(new stdClass());
        $envelope2 = new Envelope(new stdClass());

        $return = [$envelope1, $envelope2];

        $this->receiver->expects(self::once())
            ->method('get')
            ->willReturn($return);

        self::assertSame($return, $this->transport->get());
    }

    public function testAck(): void
    {
        $envelope = new Envelope(new stdClass());

        $this->receiver->expects(self::once())
            ->method('ack')
            ->with($envelope);

        $this->transport->ack($envelope);
    }

    public function testReject(): void
    {
        $envelope = new Envelope(new stdClass());

        $this->receiver->expects(self::once())
            ->method('reject')
            ->with($envelope);

        $this->transport->reject($envelope);
    }

    public function testSend(): void
    {
        $envelope = new Envelope(new stdClass());

        $this->sender->expects(self::once())
            ->method('send')
            ->with($envelope)
            ->willReturn($envelope);

        self::assertSame($envelope, $this->transport->send($envelope));
    }

    public function testGetMessageCount(): void
    {
        $this->receiver->expects(self::once())
            ->method('getMessageCount')
            ->willReturn(1);

        self::assertSame(1, $this->transport->getMessageCount());
    }

    public function testSetup(): void
    {
        $this->connection->expects(self::once())
            ->method('setup');

        $this->transport->setup();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(Connection::class);

        $this->receiver = $this->createMock(AmqpReceiver::class);

        $this->sender = $this->createMock(AmqpSender::class);

        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->transport = new AmqpTransport(
            connection: $this->connection,
            receiver: $this->receiver,
            sender: $this->sender,
            serializer: $this->serializer,
        );
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPReceiver;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPSender;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AMQPTransportTest extends TestCase
{
    private RetryFactory $retryFactory;

    /** @var Connection&MockObject */
    private Connection $connection;

    /** @var AMQPReceiver&MockObject */
    private AMQPReceiver $receiver;

    /** @var AMQPSender&MockObject */
    private AMQPSender $sender;

    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    private AMQPTransport $transport;

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

        $this->retryFactory = new RetryFactory();

        $this->connection = $this->createMock(Connection::class);

        $this->receiver = $this->createMock(AMQPReceiver::class);

        $this->sender = $this->createMock(AMQPSender::class);

        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->transport = new AMQPTransport(
            retryFactory: $this->retryFactory,
            connection: $this->connection,
            receiver: $this->receiver,
            sender: $this->sender,
            serializer: $this->serializer,
        );
    }
}

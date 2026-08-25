<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpKeepaliveReceiverInterface;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceiver;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpSender;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpTransportTest extends TestCase
{
    private function createTransport(
        Connection|null $connection = null,
        AmqpReceiver|null $receiver = null,
        AmqpSender|null $sender = null,
        SerializerInterface|null $serializer = null,
    ): AmqpTransport {
        return new AmqpTransport(
            connection: $connection ?? $this->createStub(Connection::class),
            receiver: $receiver ?? $this->createStub(AmqpReceiver::class),
            sender: $sender ?? $this->createStub(AmqpSender::class),
            serializer: $serializer ?? $this->createStub(SerializerInterface::class),
        );
    }

    public function testGetConnection(): void
    {
        $connection = $this->createStub(Connection::class);
        $transport  = $this->createTransport(connection: $connection);

        self::assertSame($connection, $transport->getConnection());
    }

    public function testGet(): void
    {
        $envelope1 = new Envelope(new stdClass());
        $envelope2 = new Envelope(new stdClass());

        $return = [$envelope1, $envelope2];

        $receiver  = $this->createMock(AmqpReceiver::class);
        $transport = $this->createTransport(receiver: $receiver);

        $receiver->expects(self::once())
            ->method('get')
            ->willReturn($return);

        self::assertSame($return, $transport->get());
    }

    public function testAck(): void
    {
        $envelope = new Envelope(new stdClass());

        $receiver  = $this->createMock(AmqpReceiver::class);
        $transport = $this->createTransport(receiver: $receiver);

        $receiver->expects(self::once())
            ->method('ack')
            ->with($envelope);

        $transport->ack($envelope);
    }

    public function testReject(): void
    {
        $envelope = new Envelope(new stdClass());

        $receiver  = $this->createMock(AmqpReceiver::class);
        $transport = $this->createTransport(receiver: $receiver);

        $receiver->expects(self::once())
            ->method('reject')
            ->with($envelope);

        $transport->reject($envelope);
    }

    public function testSend(): void
    {
        $envelope = new Envelope(new stdClass());

        $sender    = $this->createMock(AmqpSender::class);
        $transport = $this->createTransport(sender: $sender);

        $sender->expects(self::once())
            ->method('send')
            ->with($envelope)
            ->willReturn($envelope);

        self::assertSame($envelope, $transport->send($envelope));
    }

    public function testGetMessageCount(): void
    {
        $receiver  = $this->createMock(AmqpReceiver::class);
        $transport = $this->createTransport(receiver: $receiver);

        $receiver->expects(self::once())
            ->method('getMessageCount')
            ->willReturn(1);

        self::assertSame(1, $transport->getMessageCount());
    }

    public function testSetup(): void
    {
        $connection = $this->createMock(Connection::class);
        $transport  = $this->createTransport(connection: $connection);

        $connection->expects(self::once())
            ->method('setup');

        $transport->setup();
    }

    public function testImplementsKeepaliveReceiverInterface(): void
    {
        $transport = $this->createTransport();

        self::assertInstanceOf(AmqpKeepaliveReceiverInterface::class, $transport);
    }

    public function testFlush(): void
    {
        $sender    = $this->createMock(AmqpSender::class);
        $transport = $this->createTransport(sender: $sender);

        $sender->expects(self::once())
            ->method('flush');

        $transport->flush();
    }

    public function testGetFromQueues(): void
    {
        $envelope1 = new Envelope(new stdClass());
        $return    = [$envelope1];

        $receiver  = $this->createMock(AmqpReceiver::class);
        $transport = $this->createTransport(receiver: $receiver);

        $receiver->expects(self::once())
            ->method('getFromQueues')
            ->with(['queue_name'])
            ->willReturn($return);

        self::assertSame($return, $transport->getFromQueues(['queue_name']));
    }

    public function testKeepaliveCallsConnectionWhenEnabled(): void
    {
        $connectionConfig = new ConnectionConfig(keepaliveEnabled: true);

        $connection = $this->createMock(Connection::class);
        $connection->method('getConfig')->willReturn($connectionConfig);
        $connection->expects(self::once())->method('keepalive');

        $transport = $this->createTransport(connection: $connection);

        $transport->keepalive(new Envelope(new stdClass()));
    }

    public function testKeepaliveDoesNotCallConnectionWhenDisabled(): void
    {
        $connectionConfig = new ConnectionConfig(keepaliveEnabled: false);

        $connection = $this->createMock(Connection::class);
        $connection->method('getConfig')->willReturn($connectionConfig);
        $connection->expects(self::never())->method('keepalive');

        $transport = $this->createTransport(connection: $connection);

        $transport->keepalive(new Envelope(new stdClass()));
    }

    public function testKeepaliveDisabledByDefault(): void
    {
        $connectionConfig = new ConnectionConfig();

        $connection = $this->createMock(Connection::class);
        $connection->method('getConfig')->willReturn($connectionConfig);
        $connection->expects(self::never())->method('keepalive');

        $transport = $this->createTransport(connection: $connection);

        $transport->keepalive(new Envelope(new stdClass()));
    }
}

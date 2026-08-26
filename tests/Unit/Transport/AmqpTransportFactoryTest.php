<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use Jwage\PhpAmqpLibMessengerBundle\EventListener\AmqpWorkerListener;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransportFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpTransportFactoryTest extends TestCase
{
    private ConnectionFactory&Stub $connectionFactory;

    private AmqpTransportFactory $factory;

    public function testCreateTransport(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);

        $transport = $this->factory->createTransport('phpamqplib://localhost', [], $serializer);

        self::assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testSupports(): void
    {
        self::assertTrue($this->factory->supports('phpamqplib://localhost', []));
        self::assertTrue($this->factory->supports('phpamqplibs://localhost', []));
        self::assertFalse($this->factory->supports('file://localhost', []));
    }

    public function testCreateTransportRegistersTheConnectionWithTheWorkerListener(): void
    {
        $connection = $this->createStub(Connection::class);
        $this->connectionFactory->method('fromDsn')
            ->willReturn($connection);

        $listener = $this->createMock(AmqpWorkerListener::class);
        $listener->expects(self::once())
            ->method('addConnection')
            ->with('orders', $connection);

        $factory = new AmqpTransportFactory($this->connectionFactory, $listener);

        $factory->createTransport(
            'phpamqplib://localhost',
            ['transport_name' => 'orders'],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportSkipsRegistrationWithoutATransportName(): void
    {
        $listener = $this->createMock(AmqpWorkerListener::class);
        $listener->expects(self::never())
            ->method('addConnection');

        $factory = new AmqpTransportFactory($this->connectionFactory, $listener);

        $factory->createTransport(
            'phpamqplib://localhost',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportDoesNotRequireAWorkerListener(): void
    {
        $this->connectionFactory->method('fromDsn')
            ->willReturn($this->createStub(Connection::class));

        $factory = new AmqpTransportFactory($this->connectionFactory);

        $transport = $factory->createTransport(
            'phpamqplib://localhost',
            ['transport_name' => 'orders'],
            $this->createStub(SerializerInterface::class),
        );

        self::assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testCreateTransportSkipsRegistrationForAnEmptyTransportName(): void
    {
        $listener = $this->createMock(AmqpWorkerListener::class);
        $listener->expects(self::never())
            ->method('addConnection');

        $factory = new AmqpTransportFactory($this->connectionFactory, $listener);

        $factory->createTransport(
            'phpamqplib://localhost',
            ['transport_name' => ''],
            $this->createStub(SerializerInterface::class),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionFactory = $this->createStub(ConnectionFactory::class);

        $this->factory = new AmqpTransportFactory($this->connectionFactory);
    }
}

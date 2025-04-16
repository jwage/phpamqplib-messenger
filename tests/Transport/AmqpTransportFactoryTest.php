<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransportFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpTransportFactoryTest extends TestCase
{
    /** @var ConnectionFactory&MockObject */
    private ConnectionFactory $connectionFactory;

    private AmqpTransportFactory $factory;

    public function testCreateTransport(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = $this->factory->createTransport('phpamqplib://localhost', [], $serializer);

        self::assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testSupports(): void
    {
        self::assertTrue($this->factory->supports('phpamqplib://localhost', []));
        self::assertTrue($this->factory->supports('phpamqplibs://localhost', []));
        self::assertFalse($this->factory->supports('file://localhost', []));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionFactory = $this->createMock(ConnectionFactory::class);

        $this->factory = new AmqpTransportFactory($this->connectionFactory);
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function is_subclass_of;

class BatchTransportInterfaceTest extends TestCase
{
    public function testExtendsTransportInterface(): void
    {
        self::assertTrue(is_subclass_of(BatchTransportInterface::class, TransportInterface::class));
    }

    public function testAmqpTransportImplementsBatchTransportInterface(): void
    {
        $transport = new AmqpTransport($this->createStub(Connection::class));

        self::assertInstanceOf(BatchTransportInterface::class, $transport);
    }
}

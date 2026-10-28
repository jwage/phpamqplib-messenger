<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpKeepaliveReceiverInterface;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use ReflectionMethod;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;

use function interface_exists;
use function is_subclass_of;

class AmqpKeepaliveReceiverInterfaceTest extends TestCase
{
    public function testAmqpTransportImplementsTheInterface(): void
    {
        self::assertInstanceOf(
            AmqpKeepaliveReceiverInterface::class,
            new AmqpTransport($this->createStub(Connection::class)),
        );
    }

    public function testExtendsKeepaliveReceiverInterfaceWhenAvailable(): void
    {
        if (! interface_exists(KeepaliveReceiverInterface::class)) {
            self::assertFalse(is_subclass_of(
                AmqpKeepaliveReceiverInterface::class,
                KeepaliveReceiverInterface::class,
            ));

            return;
        }

        self::assertTrue(is_subclass_of(
            AmqpKeepaliveReceiverInterface::class,
            KeepaliveReceiverInterface::class,
        ));
    }

    public function testKeepaliveIsDeclared(): void
    {
        $method = new ReflectionMethod(AmqpKeepaliveReceiverInterface::class, 'keepalive');

        self::assertSame('envelope', $method->getParameters()[0]->getName());
        self::assertSame('seconds', $method->getParameters()[1]->getName());
        self::assertTrue($method->getParameters()[1]->allowsNull());
    }
}

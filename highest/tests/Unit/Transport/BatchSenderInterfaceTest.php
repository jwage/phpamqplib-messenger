<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpSender;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchSenderInterface;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class BatchSenderInterfaceTest extends TestCase
{
    public function testAmqpSenderImplementsBatchSenderInterface(): void
    {
        $sender = new AmqpSender(
            $this->createStub(Connection::class),
            $this->createStub(SerializerInterface::class),
        );

        self::assertInstanceOf(BatchSenderInterface::class, $sender);
    }
}

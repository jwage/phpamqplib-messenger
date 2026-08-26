<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Stamp;

use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferredStamp;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class DeferredStampTest extends TestCase
{
    public function testGetTransport(): void
    {
        $transport = $this->createStub(BatchTransportInterface::class);
        $stamp     = new DeferredStamp($transport);

        self::assertInstanceOf(NonSendableStampInterface::class, $stamp);
        self::assertSame($transport, $stamp->getTransport());
    }
}

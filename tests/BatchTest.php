<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Batch;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferrableStamp;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferredStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class BatchTest extends TestCase
{
    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $wrappedBus;

    /** @var BatchTransportInterface&MockObject */
    private BatchTransportInterface $transport1;

    /** @var BatchTransportInterface&MockObject */
    private BatchTransportInterface $transport2;

    private Batch $batch;

    public function testDispatch(): void
    {
        $message1 = new stdClass();
        $message2 = new stdClass();

        $envelope1 = Envelope::wrap($message1)
            ->with(new DeferrableStamp(10))
            ->with(new DeferredStamp($this->transport1));

        $envelope2 = Envelope::wrap($message2)
            ->with(new DeferrableStamp(10))
            ->with(new DeferredStamp($this->transport2));

        $this->wrappedBus->expects(self::exactly(2))
            ->method('dispatch')
            ->with($this->isInstanceOf(Envelope::class))
            ->willReturnOnConsecutiveCalls($envelope1, $envelope2);

        $this->transport1->expects(self::once())
            ->method('flush');

        $this->transport2->expects(self::once())
            ->method('flush');

        $this->batch->dispatch($message1);
        $this->batch->dispatch($message2);

        $this->batch->flush();
    }

    /** @psalm-suppress UndefinedMagicMethod */
    public function testCall(): void
    {
        $this->wrappedBus->expects(self::once())
            ->method('someMethod')
            ->willReturn('result');

        self::assertSame('result', $this->batch->someMethod('arg1', 'arg2'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wrappedBus = $this->createMock(WrappedBus::class);

        $this->transport1 = $this->createMock(BatchTransportInterface::class);

        $this->transport2 = $this->createMock(BatchTransportInterface::class);

        $this->batch = new Batch($this->wrappedBus, 10);
    }
}

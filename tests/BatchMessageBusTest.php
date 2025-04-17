<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Batch;
use Jwage\PhpAmqpLibMessengerBundle\BatchMessageBus;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class BatchMessageBusTest extends TestCase
{
    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $wrappedBus;

    private BatchMessageBus $batchMessageBus;

    public function testGetBatch(): void
    {
        $batch = $this->batchMessageBus->getBatch(1);

        self::assertInstanceOf(Batch::class, $batch);
    }

    public function testDispatch(): void
    {
        $message  = new stdClass();
        $envelope = new Envelope($message);
        $stamps   = [new DelayStamp(1000)];

        $this->wrappedBus->expects(self::once())
            ->method('dispatch')
            ->with($message, $stamps)
            ->willReturn($envelope);

        self::assertSame($envelope, $this->batchMessageBus->dispatch($message, $stamps));
    }

    /** @psalm-suppress UndefinedMagicMethod */
    public function testCall(): void
    {
        $this->wrappedBus->expects(self::once())
            ->method('someMethod')
            ->willReturn('result');

        self::assertSame('result', $this->batchMessageBus->someMethod('arg1', 'arg2'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wrappedBus = $this->createMock(WrappedBus::class);

        $this->batchMessageBus = new BatchMessageBus($this->wrappedBus);
    }
}

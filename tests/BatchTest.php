<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Batch;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferrableStamp;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferredStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

class BatchTest extends TestCase
{
    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $wrappedBus;

    private Batch $batch;

    public function testDispatch(): void
    {
        $transport1 = $this->createMock(BatchTransportInterface::class);
        $transport2 = $this->createMock(BatchTransportInterface::class);

        $message1 = new stdClass();
        $message2 = new stdClass();

        $envelope1 = $this->createEnvelope($message1, transport: $transport1);
        $envelope2 = $this->createEnvelope($message2, transport: $transport2);

        $this->wrappedBus->expects(self::exactly(2))
            ->method('dispatch')
            ->with($this->isInstanceOf(Envelope::class))
            ->willReturnOnConsecutiveCalls($envelope1, $envelope2);

        $transport1->expects(self::once())
            ->method('flush');

        $transport2->expects(self::once())
            ->method('flush');

        $this->batch->dispatch($message1);
        $this->batch->dispatch($message2);

        $this->batch->flush();
    }

    public function testDestructorFlushesTransports(): void
    {
        $transport = $this->createMock(BatchTransportInterface::class);
        $message   = new stdClass();
        $envelope  = $this->createEnvelope($message, transport: $transport);

        $this->wrappedBus->expects(self::once())
            ->method('dispatch')
            ->willReturn($envelope);

        $transport->expects(self::once())
            ->method('flush');

        $batch = new Batch($this->wrappedBus, 10);
        $batch->dispatch($message);
        unset($batch);
    }

    public function testDispatchAcceptsAssociativeStampArrays(): void
    {
        $message    = new stdClass();
        $delayStamp = new DelayStamp(1234);

        $this->expectWrappedDispatchWithStamps($message, [$delayStamp]);

        $envelope = $this->batch->dispatch($message, ['delay' => $delayStamp]);

        $this->assertEnvelopeHasMessageAndStamps($envelope, $message, [$delayStamp]);
    }

    public function testDispatchPropagatesStamps(): void
    {
        $message    = new stdClass();
        $delayStamp = new DelayStamp(1234);
        $amqpStamp  = new AmqpStamp(routingKey: 'orders');

        $this->expectWrappedDispatchWithStamps($message, [$delayStamp, $amqpStamp]);

        $envelope = $this->batch->dispatch($message, [$delayStamp, $amqpStamp]);

        $this->assertEnvelopeHasMessageAndStamps($envelope, $message, [$delayStamp, $amqpStamp]);
    }

    public function testDispatchMergesEnvelopeStampsWithDispatchStamps(): void
    {
        $message    = new stdClass();
        $delayStamp = new DelayStamp(1234);
        $amqpStamp  = new AmqpStamp(routingKey: 'orders');

        $this->expectWrappedDispatchWithStamps($message, [$delayStamp, $amqpStamp]);

        $envelope = $this->batch->dispatch(Envelope::wrap($message, [$delayStamp]), [$amqpStamp]);

        $this->assertEnvelopeHasMessageAndStamps($envelope, $message, [$delayStamp, $amqpStamp]);
    }

    public function testFlushTransportOncePerBatch(): void
    {
        $transport = $this->createMock(BatchTransportInterface::class);

        $message1 = new stdClass();

        $envelope1 = $this->createEnvelope($message1, transport: $transport);
        $envelope2 = $this->createEnvelope($message1, transport: $transport);
        $envelope3 = $this->createEnvelope($message1, transport: $transport);

        $this->wrappedBus->expects(self::exactly(3))
            ->method('dispatch')
            ->with($this->isInstanceOf(Envelope::class))
            ->willReturnOnConsecutiveCalls($envelope1, $envelope2, $envelope3);

        $transport->expects(self::once())
            ->method('flush');

        $this->batch->dispatch($message1);
        $this->batch->dispatch($message1);
        $this->batch->dispatch($message1);

        $this->batch->flush();
    }

    public function testFlushEachTransportOnce(): void
    {
        $transport1 = $this->createMock(BatchTransportInterface::class);
        $transport2 = $this->createMock(BatchTransportInterface::class);

        $message1 = new stdClass();

        $envelope1 = $this->createEnvelope($message1, transport: $transport1);
        $envelope2 = $this->createEnvelope($message1, transport: $transport1);
        $envelope3 = $this->createEnvelope($message1, transport: $transport2);

        $this->wrappedBus->expects(self::exactly(3))
            ->method('dispatch')
            ->with($this->isInstanceOf(Envelope::class))
            ->willReturnOnConsecutiveCalls($envelope1, $envelope2, $envelope3);

        $transport1->expects(self::exactly(1))
            ->method('flush');
        $transport2->expects(self::exactly(1))
            ->method('flush');

        $this->batch->dispatch($message1);
        $this->batch->dispatch($message1);
        $this->batch->dispatch($message1);

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

        $this->batch = new Batch($this->wrappedBus, 10);
    }

    /** @param list<StampInterface> $stamps */
    private function expectWrappedDispatchWithStamps(object $message, array $stamps): void
    {
        $this->wrappedBus->expects(self::once())
            ->method('dispatch')
            ->with($this->callback(function (Envelope $envelope) use ($message, $stamps): bool {
                $this->assertEnvelopeHasMessageAndStamps($envelope, $message, $stamps);

                return true;
            }))
            ->willReturnArgument(0);
    }

    /** @param list<StampInterface> $stamps */
    private function assertEnvelopeHasMessageAndStamps(Envelope $envelope, object $message, array $stamps): void
    {
        self::assertSame($message, $envelope->getMessage());

        foreach ($stamps as $stamp) {
            self::assertSame($stamp, $envelope->last($stamp::class));
        }

        self::assertSame(10, $envelope->last(DeferrableStamp::class)?->getBatchSize());
    }

    private function createEnvelope(stdClass $message, int $batchSize = 10, BatchTransportInterface|null $transport = null): Envelope
    {
        return Envelope::wrap($message)
            ->with(new DeferrableStamp($batchSize))
            ->with(new DeferredStamp($transport ?? $this->createStub(BatchTransportInterface::class)));
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\BatchMessageBus;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

class BatchMessageBusTest extends TestCase
{
    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $wrappedBus;

    /** @var TransportInterface&MockObject */
    private TransportInterface $transport1;

    /** @var TransportInterface&BatchTransportInterface&MockObject */
    private TransportInterface $transport2;

    /** @var array<TransportInterface> */
    private array $transports;

    private BatchMessageBus $batchMessageBus;

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

    public function testDispatchBatches(): void
    {
        $message1 = new stdClass();
        $message2 = new stdClass();
        $message3 = new stdClass();
        $message4 = new stdClass();
        $message5 = new stdClass();

        $messages  = [$message1, $message2, $message3, $message4, $message5];
        $batchSize = 2;

        $envelope1 = new Envelope($message1);
        $envelope2 = new Envelope($message2);
        $envelope3 = new Envelope($message3);
        $envelope4 = new Envelope($message4);
        $envelope5 = new Envelope($message5);

        $this->wrappedBus->expects(self::exactly(5))
            ->method('dispatch')
            ->willReturnOnConsecutiveCalls($envelope1, $envelope2, $envelope3, $envelope4, $envelope5);

        $this->transport2->expects(self::exactly(3))
            ->method('flush');

        $this->batchMessageBus->dispatchBatches($messages, $batchSize);
    }

    public function testDispatchInBatch(): void
    {
        $message  = new stdClass();
        $envelope = new Envelope($message);

        $this->wrappedBus->expects(self::once())
            ->method('dispatch')
            ->willReturn($envelope);

        $this->batchMessageBus->dispatchInBatch($message, 100);
    }

    public function testFlush(): void
    {
        $this->transport2->expects(self::once())
            ->method('flush');

        $this->batchMessageBus->flush();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wrappedBus = $this->createMock(MessageBusInterface::class);

        $this->transport1 = $this->createMock(TransportInterface::class);
        $this->transport2 = $this->createMock(BatchTransportInterface::class);

        $this->transports = [
            $this->transport1,
            $this->transport2,
        ];

        $this->batchMessageBus = new BatchMessageBus($this->wrappedBus, $this->transports);
    }
}

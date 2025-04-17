<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Batch;
use Jwage\PhpAmqpLibMessengerBundle\BatchMessageBus;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class BatchMessageBusTest extends TestCase
{
    private WrappedBus $wrappedBus;

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

        $this->wrappedBus->dispatch($message, $stamps);

        $last = $this->wrappedBus->popEnvelope();
        self::assertEquals(
            new Envelope($message, $stamps),
            $last,
        );
    }

    /** @psalm-suppress UndefinedMagicMethod */
    public function testCall(): void
    {
        self::assertSame('result', $this->batchMessageBus->someMethod('arg2', 'arg2'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wrappedBus = new WrappedBus();

        $this->batchMessageBus = new BatchMessageBus($this->wrappedBus);
    }
}

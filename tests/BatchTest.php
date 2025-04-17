<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Batch;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\Deferrable;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\Flush;
use stdClass;

class BatchTest extends TestCase
{
    private WrappedBus $wrappedBus;

    private Batch $batch;

    public function testDispatch(): void
    {
        $message1 = new stdClass();
        $message2 = new stdClass();

        $this->batch->dispatch($message1);
        $this->batch->flush();

        $flushEnvelope = $this->wrappedBus->popEnvelope();
        self::assertInstanceOf(Flush::class, $flushEnvelope->getMessage());

        $messageEnvelope = $this->wrappedBus->popEnvelope();
        $deferrableStamp = $messageEnvelope->last(Deferrable::class);
        self::assertNotNull($deferrableStamp);
        self::assertEquals(10, $deferrableStamp->getBatchSize());
    }

    /** @psalm-suppress UndefinedMagicMethod */
    public function testCall(): void
    {
        self::assertSame('result', $this->batch->someMethod('arg1', 'arg2'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wrappedBus = new WrappedBus();

        $this->batch = new Batch($this->wrappedBus, 10);
    }
}

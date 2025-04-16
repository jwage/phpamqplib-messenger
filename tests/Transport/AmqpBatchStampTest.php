<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpBatchStamp;

class AmqpBatchStampTest extends TestCase
{
    private AmqpBatchStamp $stamp;

    public function testConstruct(): void
    {
        self::assertSame(100, $this->stamp->getBatchSize());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stamp = new AmqpBatchStamp(100);
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Stamp;

use Jwage\PhpAmqpLibMessengerBundle\Stamp\DeferrableStamp;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;

class DeferrableStampTest extends TestCase
{
    public function testGetBatchSize(): void
    {
        $stamp = new DeferrableStamp(10);

        self::assertInstanceOf(StampInterface::class, $stamp);
        self::assertSame(10, $stamp->getBatchSize());
    }
}

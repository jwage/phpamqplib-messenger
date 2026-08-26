<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Debug;
use Psr\Log\LoggerInterface;

class DebugTest extends TestCase
{
    public function testLogIsANoOpWhenDebugIsDisabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        (new Debug($logger, false))->log('Waiting on AMQP sockets', ['sockets' => 1]);
    }

    public function testLogIsANoOpWithoutALogger(): void
    {
        self::expectNotToPerformAssertions();

        (new Debug(null, true))->log('Waiting on AMQP sockets');
    }

    public function testLogWritesDebugWhenEnabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with('Waiting on AMQP sockets', ['sockets' => 2]);

        (new Debug($logger, true))->log('Waiting on AMQP sockets', ['sockets' => 2]);
    }
}

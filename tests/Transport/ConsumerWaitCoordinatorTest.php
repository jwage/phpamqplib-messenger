<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;

class ConsumerWaitCoordinatorTest extends TestCase
{
    public function testWaitKeepalivesRegisteredConnections(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->expects(self::once())
            ->method('keepalive');
        $connection->expects(self::never())
            ->method('drainConsumerChannel');

        $coordinator = new ConsumerWaitCoordinator();
        $coordinator->register($connection);
        $coordinator->wait(0.01);
    }

    public function testWaitCoalescesSubsequentCallsUntilReset(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->expects(self::once())
            ->method('keepalive');
        $connection->expects(self::once())
            ->method('drainConsumerChannel');

        $coordinator = new ConsumerWaitCoordinator();
        $coordinator->register($connection);
        $coordinator->wait(0.01);
        $coordinator->wait(0.01);
    }

    public function testResetAllowsTheNextPassToWaitAgain(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->expects(self::exactly(2))
            ->method('keepalive');

        $coordinator = new ConsumerWaitCoordinator();
        $coordinator->register($connection);
        $coordinator->wait(0.01);
        $coordinator->reset();
        $coordinator->wait(0.01);
    }

    public function testWaitWithoutCoalesceAlwaysSelects(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->expects(self::exactly(2))
            ->method('keepalive');
        $connection->expects(self::never())
            ->method('drainConsumerChannel');

        $coordinator = new ConsumerWaitCoordinator();
        $coordinator->register($connection);
        $coordinator->wait(0.01, coalesce: false);
        $coordinator->wait(0.01, coalesce: false);
    }

    public function testUnregisterRemovesTheConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())
            ->method('getConsumerSocket');
        $connection->expects(self::never())
            ->method('keepalive');
        $connection->expects(self::never())
            ->method('drainConsumerChannel');

        $coordinator = new ConsumerWaitCoordinator();
        $coordinator->register($connection);
        $coordinator->unregister($connection);
        $coordinator->wait(0.01);
    }
}

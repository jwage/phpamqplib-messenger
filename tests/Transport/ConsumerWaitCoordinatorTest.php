<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Debug;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\CollectingLogger;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Symfony\Component\Messenger\Exception\TransportException;

use function fclose;
use function fwrite;
use function hrtime;
use function stream_set_blocking;
use function stream_socket_pair;

use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

class ConsumerWaitCoordinatorTest extends TestCase
{
    public function testWaitKeepalivesRegisteredConnections(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->expects(self::once())
            ->method('keepalive');
        $connection->expects(self::once())
            ->method('drainConsumerChannel')
            ->willReturn(false);

        $coordinator = new ConsumerWaitCoordinator();
        $coordinator->register($connection);
        $coordinator->wait(0.01);
    }

    public function testWaitCoalescesSubsequentCallsUntilReset(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->expects(self::once())
            ->method('keepalive');
        $connection->expects(self::exactly(2))
            ->method('drainConsumerChannel')
            ->willReturn(false);

        $logger      = new CollectingLogger();
        $coordinator = new ConsumerWaitCoordinator(new Debug($logger, true));
        $coordinator->register($connection);
        $coordinator->wait(0.01);
        $coordinator->wait(0.01);

        self::assertTrue($logger->hasTemplate('Coalesced wait; draining without selecting'));
    }

    public function testResetAllowsTheNextPassToWaitAgain(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->method('drainConsumerChannel')->willReturn(false);
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
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->expects(self::exactly(2))
            ->method('keepalive');
        $connection->expects(self::exactly(2))
            ->method('drainConsumerChannel')
            ->willReturn(false);

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

    public function testWaitKeepaliveFailuresDoNotStopTheWait(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->method('keepalive')
            ->willThrowException(new TransportException('heartbeat failed'));
        $connection->expects(self::once())
            ->method('drainConsumerChannel')
            ->willReturn(false);

        $coordinator = new ConsumerWaitCoordinator();
        $coordinator->register($connection);
        $coordinator->wait(0.01);
    }

    public function testWaitDrainsASocketAfterSkippingAConnectionWithoutOne(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $withoutSocket = $this->createMock(Connection::class);
            $withoutSocket->method('getConsumerSocket')->willReturn(null);
            $withoutSocket->method('hasBufferedDeliveries')->willReturn(false);
            $withoutSocket->expects(self::once())
                ->method('keepalive');
            $withoutSocket->expects(self::once())
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $withSocket = $this->createMock(Connection::class);
            $withSocket->method('getConsumerSocket')->willReturn($left);
            $withSocket->method('hasBufferedDeliveries')->willReturn(false);
            $withSocket->expects(self::once())
                ->method('keepalive');
            $withSocket->expects(self::exactly(2))
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $coordinator = new ConsumerWaitCoordinator();
            $coordinator->register($withoutSocket);
            $coordinator->register($withSocket);
            $coordinator->wait(0.05);
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testWaitDrainsOnlySocketsThatAreReadable(): void
    {
        $readyPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $idlePair  = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($readyPair === false || $idlePair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$readyLeft, $readyRight] = $readyPair;
        [$idleLeft, $idleRight]   = $idlePair;
        stream_set_blocking($readyLeft, false);
        stream_set_blocking($readyRight, false);
        stream_set_blocking($idleLeft, false);
        stream_set_blocking($idleRight, false);

        try {
            fwrite($readyRight, 'x');

            $ready = $this->createMock(Connection::class);
            $ready->method('getConsumerSocket')->willReturn($readyLeft);
            $ready->method('hasBufferedDeliveries')->willReturn(false);
            $ready->expects(self::exactly(2))
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $idle = $this->createMock(Connection::class);
            $idle->method('getConsumerSocket')->willReturn($idleLeft);
            $idle->method('hasBufferedDeliveries')->willReturn(false);
            $idle->expects(self::once())
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $coordinator = new ConsumerWaitCoordinator();
            $coordinator->register($idle);
            $coordinator->register($ready);
            $coordinator->wait(0.05);
        } finally {
            fclose($readyLeft);
            fclose($readyRight);
            fclose($idleLeft);
            fclose($idleRight);
        }
    }

    public function testWaitHonorsTheIdleWaitFloor(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $connection = $this->createMock(Connection::class);
            $connection->method('getConsumerSocket')->willReturn($left);
            $connection->method('hasBufferedDeliveries')->willReturn(false);
            $connection->expects(self::exactly(2))
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $logger      = new CollectingLogger();
            $coordinator = new ConsumerWaitCoordinator(new Debug($logger, true));
            $coordinator->setWaitFloor(0.2);
            $coordinator->register($connection);

            $start = hrtime(true);
            $coordinator->wait(0.05);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            self::assertGreaterThan(150, $elapsedMs);
            self::assertTrue($logger->hasTemplate('Wait floor set'));
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testWaitDoesNotExtendAZeroWaitFloor(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $connection = $this->createMock(Connection::class);
            $connection->method('getConsumerSocket')->willReturn($left);
            $connection->method('hasBufferedDeliveries')->willReturn(false);
            $connection->expects(self::exactly(2))
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $coordinator = new ConsumerWaitCoordinator();
            $coordinator->setWaitFloor(0.0);
            $coordinator->register($connection);

            $start = hrtime(true);
            $coordinator->wait(0.05);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            self::assertLessThan(200, $elapsedMs);
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testWaitSkipsSelectWhenAPreDrainBuffersADelivery(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $connection = $this->createMock(Connection::class);
            $connection->method('getConsumerSocket')->willReturn($left);
            $connection->method('hasBufferedDeliveries')->willReturn(true);
            $connection->expects(self::once())
                ->method('drainConsumerChannel')
                ->willReturn(true);

            $logger      = new CollectingLogger();
            $coordinator = new ConsumerWaitCoordinator(new Debug($logger, true));
            $coordinator->register($connection);

            $start = hrtime(true);
            $coordinator->wait(5.0);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            self::assertLessThan(200, $elapsedMs);
            self::assertTrue($logger->hasTemplate('Skipping stream_select; a delivery is already buffered'));
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testWaitSkipsSelectWhenAnyConnectionHasABufferedDelivery(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $withDelivery = $this->createMock(Connection::class);
            $withDelivery->method('getConsumerSocket')->willReturn($left);
            $withDelivery->method('hasBufferedDeliveries')->willReturn(true);
            $withDelivery->expects(self::once())
                ->method('drainConsumerChannel');

            $withoutDelivery = $this->createMock(Connection::class);
            $withoutDelivery->method('getConsumerSocket')->willReturn($left);
            $withoutDelivery->method('hasBufferedDeliveries')->willReturn(false);
            $withoutDelivery->expects(self::once())
                ->method('drainConsumerChannel');

            $coordinator = new ConsumerWaitCoordinator();
            $coordinator->register($withDelivery);
            $coordinator->register($withoutDelivery);

            $start = hrtime(true);
            $coordinator->wait(5.0);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            self::assertLessThan(200, $elapsedMs);
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testWaitDoesNotSkipSelectWhenAPreDrainFindsNoDelivery(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $connection = $this->createMock(Connection::class);
            $connection->method('getConsumerSocket')->willReturn($left);
            $connection->method('hasBufferedDeliveries')->willReturn(false);
            $connection->expects(self::exactly(2))
                ->method('drainConsumerChannel')
                ->willReturn(true);

            $logger      = new CollectingLogger();
            $coordinator = new ConsumerWaitCoordinator(new Debug($logger, true));
            $coordinator->register($connection);

            $start = hrtime(true);
            $coordinator->wait(0.15);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            self::assertGreaterThan(100, $elapsedMs);
            self::assertTrue($logger->hasTemplate('Waiting on AMQP sockets'));
            self::assertTrue($logger->hasTemplate('stream_select finished'));
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testWaitReturnsImmediatelyWhenNoSocketsAreAvailable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->expects(self::once())
            ->method('drainConsumerChannel')
            ->willReturn(false);

        $logger      = new CollectingLogger();
        $coordinator = new ConsumerWaitCoordinator(new Debug($logger, true));
        $coordinator->register($connection);

        $start = hrtime(true);
        $coordinator->wait(0.5);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(150, $elapsedMs);
        self::assertTrue($logger->hasTemplate('No AMQP sockets to wait on'));
    }

    public function testWaitDoesNotLogWhenDebugIsDisabled(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->method('drainConsumerChannel')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $coordinator = new ConsumerWaitCoordinator(new Debug($logger, false));
        $coordinator->register($connection);
        $coordinator->wait(0.01);
    }

    /** @param list{int, int} $expected */
    #[DataProvider('provideSplitTimeouts')]
    public function testSplitTimeoutConvertsSecondsAndMicroseconds(float $timeout, array $expected): void
    {
        $method = new ReflectionMethod(ConsumerWaitCoordinator::class, 'splitTimeout');

        self::assertSame($expected, $method->invoke(new ConsumerWaitCoordinator(), $timeout));
    }

    /** @return iterable<string, array{float, list{int, int}}> */
    public static function provideSplitTimeouts(): iterable
    {
        yield 'one and a half seconds' => [1.5, [1, 500000]];
        yield 'exactly one second' => [1.0, [1, 0]];
        yield 'just under one million microseconds' => [0.999999, [0, 999999]];
        yield 'fraction that rounds up to a whole second' => [0.9999996, [1, 0]];
        yield 'half-up rounding of a sub-microsecond fraction' => [1.0000006, [1, 1]];
        yield 'half-down rounding of a sub-microsecond fraction' => [1.0000004, [1, 0]];
        yield 'two point three seconds' => [2.3, [2, 300000]];
    }
}

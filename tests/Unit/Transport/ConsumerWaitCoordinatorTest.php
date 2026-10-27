<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Debug;
use Jwage\PhpAmqpLibMessengerBundle\Tests\CollectingLogger;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Symfony\Component\Messenger\Exception\TransportException;

use function assert;
use function fclose;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function hrtime;
use function is_callable;
use function is_int;
use function pcntl_alarm;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function restore_error_handler;
use function set_error_handler;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_pair;

use const SIGALRM;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

class ConsumerWaitCoordinatorTest extends TestCase
{
    #[DataProvider('provideAlarmHandlingModes')]
    public function testWaitHandlesAnAlarmWithoutReportingAWarningAndCanWaitAgain(bool $asyncSignals): void
    {
        if (! function_exists('pcntl_alarm')) {
            self::markTestSkipped('The pcntl extension is required to interrupt a socket wait.');
        }

        $pair     = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $idlePair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false || $idlePair === false) {
            self::fail('stream_socket_pair failed');
        }

        [$left, $right]         = $pair;
        [$idleLeft, $idleRight] = $idlePair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);
        stream_set_blocking($idleLeft, false);
        stream_set_blocking($idleRight, false);

        $warnings       = [];
        $handledSignals = [];
        $errorHandler   = static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        };

        $previousSignalHandler = pcntl_signal_get_handler(SIGALRM);
        assert(is_int($previousSignalHandler) || is_callable($previousSignalHandler));
        $previousAsyncSignals = pcntl_async_signals($asyncSignals);
        pcntl_signal(SIGALRM, static function (int $signal) use (&$handledSignals): void {
            $handledSignals[] = $signal;
        }, false);
        set_error_handler($errorHandler);

        try {
            $idle = $this->createMock(Connection::class);
            $idle->method('getConsumerSocket')->willReturn($idleLeft);
            $idle->method('hasBufferedDeliveries')->willReturn(false);
            $idle->expects(self::exactly(3))
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $connection = $this->createMock(Connection::class);
            $connection->method('getConsumerSocket')->willReturn($left);
            $connection->method('hasBufferedDeliveries')->willReturn(false);
            $connection->expects(self::exactly(4))
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $logger      = new CollectingLogger();
            $coordinator = new ConsumerWaitCoordinator(new Debug($logger, true));
            $coordinator->register($idle);
            $coordinator->register($connection);

            pcntl_alarm(1);
            $coordinator->wait(5.0);

            self::assertSame([SIGALRM], $handledSignals);
            $restoredHandler = set_error_handler($errorHandler);
            restore_error_handler();
            self::assertSame($errorHandler, $restoredHandler);

            fwrite($right, 'message');
            $logger->records = [];
            $coordinator->reset();
            $coordinator->wait(0.05);

            self::assertSame(['selected' => 1, 'sockets' => 2], $logger->contextFor('stream_select finished'));
            self::assertSame('message', fread($left, 7));
            self::assertSame('', fread($idleLeft, 7));
            self::assertSame([], $warnings);
        } finally {
            pcntl_alarm(0);
            restore_error_handler();
            pcntl_signal(SIGALRM, $previousSignalHandler);
            pcntl_async_signals($previousAsyncSignals);
            fclose($left);
            fclose($right);
            fclose($idleLeft);
            fclose($idleRight);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function provideAlarmHandlingModes(): iterable
    {
        yield 'synchronous signals' => [false];
        yield 'asynchronous signals' => [true];
    }

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

        self::assertSame(
            [
                'timeout' => 0.01,
                'connections' => 1,
            ],
            $logger->contextFor('Coalesced wait; draining without selecting'),
        );
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

        $start = hrtime(true);
        $coordinator->wait(0.3);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(100, $elapsedMs);
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
            self::assertSame(['wait_floor' => 0.2], $logger->contextFor('Wait floor set'));
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
            self::assertSame(
                ['connections' => 1],
                $logger->contextFor('Skipping stream_select; a delivery is already buffered'),
            );
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
            self::assertSame(
                [
                    'timeout' => 0.15,
                    'seconds' => 0,
                    'microseconds' => 150000,
                    'sockets' => 1,
                ],
                $logger->contextFor('Waiting on AMQP sockets'),
            );
            self::assertSame(
                [
                    'selected' => 0,
                    'sockets' => 1,
                ],
                $logger->contextFor('stream_select finished'),
            );
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    public function testWaitReportsZeroSelectedSocketsWhenSelectingFails(): void
    {
        $descriptors = [];
        $pair        = false;

        try {
            // A valid socket above FD_SETSIZE produces a genuine select warning,
            // unlike a closed PHP resource, which raises a programming error.
            for ($i = 0; $i < 1100; $i++) {
                $descriptor = @fopen('/dev/null', 'r');
                if ($descriptor === false) {
                    self::markTestSkipped('The descriptor limit is too low to exercise FD_SETSIZE.');
                }

                $descriptors[] = $descriptor;
            }

            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false) {
                self::fail('stream_socket_pair failed');
            }

            [$left] = $pair;

            $read    = [$left];
            $write   = $except = null;
            $warning = null;
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;

                return true;
            });
            try {
                stream_select($read, $write, $except, 0);
            } finally {
                restore_error_handler();
            }

            if ($warning === null) {
                self::markTestSkipped('This PHP build supports selecting the allocated high descriptor.');
            }

            $connection = $this->createMock(Connection::class);
            $connection->method('getConsumerSocket')->willReturn($left);
            $connection->method('hasBufferedDeliveries')->willReturn(false);
            $connection->expects(self::exactly(2))
                ->method('drainConsumerChannel')
                ->willReturn(false);

            $logger      = new CollectingLogger();
            $coordinator = new ConsumerWaitCoordinator(new Debug($logger, true), $logger);
            $coordinator->register($connection);

            $coordinator->wait(0.01);

            self::assertSame(
                ['selected' => 0, 'sockets' => 1],
                $logger->contextFor('stream_select finished'),
            );
        } finally {
            foreach ($descriptors as $descriptor) {
                fclose($descriptor);
            }

            if ($pair !== false) {
                foreach ($pair as $socket) {
                    fclose($socket);
                }
            }
        }
    }

    public function testWaitBlocksWhenNoSocketsAreAvailable(): void
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
        $coordinator->wait(0.15);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertGreaterThan(100, $elapsedMs);
        self::assertSame(
            [
                'timeout' => 0.15,
                'connections' => 1,
            ],
            $logger->contextFor('No AMQP sockets to wait on'),
        );
        self::assertFalse($logger->hasTemplate('Waiting on AMQP sockets'));
        self::assertFalse($logger->hasTemplate('stream_select finished'));
    }

    public function testWaitDoesNotSleepWhenNoConnectionsAreRegistered(): void
    {
        $logger      = new CollectingLogger();
        $coordinator = new ConsumerWaitCoordinator(new Debug($logger, true));

        $start = hrtime(true);
        $coordinator->wait(0.15);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(50, $elapsedMs);
        self::assertSame(
            [
                'timeout' => 0.15,
                'connections' => 0,
            ],
            $logger->contextFor('No AMQP sockets to wait on'),
        );
        self::assertFalse($logger->hasTemplate('Waiting on AMQP sockets'));
        self::assertFalse($logger->hasTemplate('stream_select finished'));
    }

    public function testWaitReturnsImmediatelyWhenNoSocketsAndTimeoutIsZero(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->expects(self::once())
            ->method('drainConsumerChannel')
            ->willReturn(false);

        $coordinator = new ConsumerWaitCoordinator();
        $coordinator->register($connection);

        $start = hrtime(true);
        $coordinator->wait(0.0);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(50, $elapsedMs);
    }

    public function testWaitDoesNotLogWhenDebugIsDisabled(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConsumerSocket')->willReturn(null);
        $connection->method('hasBufferedDeliveries')->willReturn(false);
        $connection->method('drainConsumerChannel')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $coordinator = new ConsumerWaitCoordinator(new Debug($logger, false));
        $coordinator->register($connection);
        $coordinator->wait(0.0);
    }

    /** @param list{int, int} $expected */
    #[DataProvider('provideSplitTimeouts')]
    public function testSplitTimeoutConvertsSecondsAndMicroseconds(float $timeout, array $expected): void
    {
        $method = new ReflectionMethod(ConsumerWaitCoordinator::class, 'splitTimeout');

        self::assertSame($expected, $method->invoke(new ConsumerWaitCoordinator(), $timeout));
    }

    #[DataProvider('provideTimeoutMicroseconds')]
    public function testTimeoutToMicrosecondsConvertsWholeAndFractionalSeconds(float $timeout, int $expected): void
    {
        $method = new ReflectionMethod(ConsumerWaitCoordinator::class, 'timeoutToMicroseconds');

        self::assertSame($expected, $method->invoke(new ConsumerWaitCoordinator(), $timeout));
    }

    /** @return iterable<string, array{float, list{int, int}}> */
    public static function provideSplitTimeouts(): iterable
    {
        yield 'zero' => [0.0, [0, 0]];
        yield 'one and a half seconds' => [1.5, [1, 500000]];
        yield 'exactly one second' => [1.0, [1, 0]];
        yield 'just under one million microseconds' => [0.999999, [0, 999999]];
        yield 'fraction that rounds up to a whole second' => [0.9999996, [1, 0]];
        yield 'half-up rounding of a sub-microsecond fraction' => [1.0000006, [1, 1]];
        yield 'half-down rounding of a sub-microsecond fraction' => [1.0000004, [1, 0]];
        yield 'two point three seconds' => [2.3, [2, 300000]];
        yield 'one hundred fifty milliseconds' => [0.15, [0, 150000]];
    }

    /** @return iterable<string, array{float, int}> */
    public static function provideTimeoutMicroseconds(): iterable
    {
        yield 'zero' => [0.0, 0];
        yield 'one and a half seconds' => [1.5, 1_500_000];
        yield 'exactly one second' => [1.0, 1_000_000];
        yield 'just under one million microseconds' => [0.999999, 999_999];
        yield 'fraction that rounds up to a whole second' => [0.9999996, 1_000_000];
        yield 'half-up rounding of a sub-microsecond fraction' => [1.0000006, 1_000_001];
        yield 'half-down rounding of a sub-microsecond fraction' => [1.0000004, 1_000_000];
        yield 'two point three seconds' => [2.3, 2_300_000];
        yield 'one hundred fifty milliseconds' => [0.15, 150_000];
    }
}

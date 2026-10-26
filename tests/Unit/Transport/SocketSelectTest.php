<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit\Transport;

use ErrorException;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\SocketSelect;
use PhpAmqpLib\Exception\AMQPIOWaitException;

use function assert;
use function error_clear_last;
use function error_get_last;
use function fclose;
use function fopen;
use function function_exists;
use function fwrite;
use function is_callable;
use function is_int;
use function pcntl_alarm;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function restore_error_handler;
use function set_error_handler;
use function stream_socket_pair;

use const E_WARNING;
use const SIGALRM;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

class SocketSelectTest extends TestCase
{
    public function testSelectReturnsOnlyReadableSocketsWithTheirOriginalKeys(): void
    {
        $ready = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $idle  = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($ready);
        self::assertNotFalse($idle);

        try {
            fwrite($ready[1], 'message');
            $read = ['idle' => $idle[0], 'ready' => $ready[0]];

            self::assertSame(1, SocketSelect::select($read, 0, 0));
            self::assertSame(['ready' => $ready[0]], $read);
        } finally {
            fclose($ready[0]);
            fclose($ready[1]);
            fclose($idle[0]);
            fclose($idle[1]);
        }
    }

    public function testSelectReturnsZeroWhenNoSocketIsReady(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($pair);

        try {
            $read = [$pair[0]];
            self::assertSame(0, SocketSelect::select($read, 0, 0));
            self::assertSame([], $read);
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    public function testSelectContainsAnInterruptAndRestoresTheErrorHandler(): void
    {
        if (! function_exists('pcntl_alarm')) {
            self::markTestSkipped('The pcntl extension is required to interrupt a socket wait.');
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($pair);
        $warnings      = [];
        $handler       = static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        };
        $signalHandler = pcntl_signal_get_handler(SIGALRM);
        assert(is_int($signalHandler) || is_callable($signalHandler));
        $asyncSignals = pcntl_async_signals(false);
        $signals      = [];
        pcntl_signal(SIGALRM, static function (int $signal) use (&$signals): void {
            $signals[] = $signal;
        }, false);
        set_error_handler($handler);

        try {
            $read = [$pair[0]];
            pcntl_alarm(1);
            error_clear_last();
            self::assertSame(0, SocketSelect::select($read, 5, 0));
            self::assertSame([], $warnings);
            self::assertNull(error_get_last());
            $restoredHandler = set_error_handler($handler);
            restore_error_handler();
            self::assertSame($handler, $restoredHandler);
        } finally {
            pcntl_alarm(0);
            restore_error_handler();
            pcntl_signal(SIGALRM, $signalHandler);
            pcntl_async_signals($asyncSignals);
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    public function testSelectReportsAnUnsupportedStreamAndRestoresTheErrorHandler(): void
    {
        $pair   = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($pair);
        self::assertNotFalse($memory);
        $warnings = [];
        $handler  = static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        };
        set_error_handler($handler);

        try {
            $read = [$memory, $pair[0]];

            try {
                SocketSelect::select($read, 0, 0);
                self::fail('An unsupported stream must produce an I/O wait exception.');
            } catch (AMQPIOWaitException $exception) {
                self::assertSame(0, $exception->getCode());
                self::assertStringContainsString('Cannot represent a stream', $exception->getMessage());
                $previous = $exception->getPrevious();
                self::assertInstanceOf(ErrorException::class, $previous);
                self::assertSame($exception->getMessage(), $previous->getMessage());
                self::assertSame(E_WARNING, $previous->getSeverity());
            }

            self::assertSame([], $warnings);
            $restoredHandler = set_error_handler($handler);
            restore_error_handler();
            self::assertSame($handler, $restoredHandler);
        } finally {
            restore_error_handler();
            fclose($memory);
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    public function testSelectReportsAClosedStreamAsAnIoWaitException(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($pair);
        fclose($pair[1]);
        $closed = $pair[0];
        fclose($closed);

        $read = [$closed];

        $this->expectException(AMQPIOWaitException::class);

        /** @psalm-suppress InvalidArgument */
        SocketSelect::select($read, 0, 0);
    }
}

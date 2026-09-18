<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use ErrorException;
use PhpAmqpLib\Exception\AMQPIOWaitException;

use function assert;
use function function_exists;
use function is_resource;
use function pcntl_signal_dispatch;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;
use function stream_select;

use const E_WARNING;
use const SOCKET_EAGAIN;
use const SOCKET_EINTR;
use const SOCKET_EWOULDBLOCK;

/** @internal */
final class SocketSelect
{
    /**
     * @param array<array-key, resource|object> $read
     *
     * @throws AMQPIOWaitException
     *
     * @param-out array<array-key, resource> $read
     */
    public static function select(array &$read, int $seconds, int $microseconds = 0): int
    {
        $streams = [];
        foreach ($read as $key => $socket) {
            assert(is_resource($socket));
            $streams[$key] = $socket;
        }

        $error = null;
        /** @var callable(int, string, string, int): mixed|null $previousHandler */
        $previousHandler = null;
        $previousHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$error, &$previousHandler): bool {
            // Async signal callbacks can emit unrelated errors during this wait.
            if ($severity !== E_WARNING || $file !== __FILE__ || ! str_starts_with($message, 'stream_select():')) {
                return $previousHandler !== null ? $previousHandler($severity, $message, $file, $line) !== false : false;
            }

            $code = preg_match('/\s+\[(\d+)\]:\s+/', $message, $matches) === 1 ? (int) $matches[1] : 0;

            // Match php-amqplib's StreamIO handling of retryable socket conditions.
            if ($code === SOCKET_EINTR || $code === SOCKET_EAGAIN || $code === SOCKET_EWOULDBLOCK) {
                return true;
            }

            $error = new ErrorException($message, $code > 0 ? $code : $severity, $severity, $file, $line, $error);

            return true;
        });

        try {
            $write    = null;
            $except   = null;
            $selected = stream_select($streams, $write, $except, $seconds, $microseconds);
            $read     = $streams;

            if ($error !== null) {
                throw new AMQPIOWaitException($error->getMessage(), $error->getCode(), $error);
            }
        } finally {
            restore_error_handler();
        }

        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $selected === false ? 0 : $selected;
    }
}

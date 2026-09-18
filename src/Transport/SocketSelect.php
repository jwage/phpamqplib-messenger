<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use ErrorException;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Helper\SocketConstants;

use function assert;
use function function_exists;
use function is_resource;
use function pcntl_signal_dispatch;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function stream_select;

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
    public static function select(array &$read, int $seconds, int|null $microseconds): int
    {
        $streams = [];
        foreach ($read as $key => $socket) {
            assert(is_resource($socket));
            $streams[$key] = $socket;
        }

        $error     = null;
        $constants = SocketConstants::getInstance();
        /** @var callable(int, string, string, int): mixed|null $previousHandler */
        $previousHandler = null;
        $previousHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$error, &$previousHandler, $constants): bool {
            // The only warning-producing operation in this file is stream_select().
            // Async signal callbacks and stream wrappers can emit errors elsewhere.
            if ($file !== __FILE__) {
                return $previousHandler !== null ? $previousHandler($severity, $message, $file, $line) !== false : false;
            }

            $code = preg_match('/\s+\[([1-9]\d*)\]:\s+/', $message, $matches) === 1 ? (int) $matches[1] : $severity;

            // Match php-amqplib's StreamIO handling of retryable socket conditions.
            if ($code === $constants->__get('SOCKET_EINTR')) {
                return true;
            }

            if ($code === $constants->__get('SOCKET_EAGAIN')) {
                return true;
            }

            if ($code === $constants->__get('SOCKET_EWOULDBLOCK')) {
                return true;
            }

            $error = new ErrorException($message, $code, $severity, $file, $line, $error);

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

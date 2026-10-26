<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use ErrorException;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Helper\SocketConstants;

use function is_resource;
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
            // A closed or non-stream socket makes stream_select() throw; report it like any other wait failure.
            if (! is_resource($socket)) {
                throw new AMQPIOWaitException('Cannot wait on a socket that is not a valid stream resource');
            }

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

            $code = preg_match('/\s+\[([1-9]\d*)\]:\s+/', $message, $matches) === 1 ? (int) $matches[1] : 0;

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

            // A warning alongside a positive result does not invalidate the ready sockets.
            if ($error !== null && ($selected === false || $selected === 0)) {
                throw new AMQPIOWaitException($error->getMessage(), $error->getCode(), $error);
            }
        } finally {
            restore_error_handler();
        }

        return $selected === false ? 0 : $selected;
    }
}

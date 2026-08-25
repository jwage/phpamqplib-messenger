<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

use function array_values;
use function function_exists;
use function pcntl_signal_dispatch;
use function round;
use function spl_object_id;
use function stream_select;

/**
 * Multiplexed AMQP wait used by AmqpWorkerListener while the worker is idle.
 *
 * Symfony's worker polls every receiver, then sleeps. This coordinator waits
 * on every registered connection's socket at once so a message on any
 * phpamqplib transport wakes the process without blocking get() itself.
 */
class ConsumerWaitCoordinator
{
    /** @var array<int, Connection> */
    private array $connections = [];

    public function register(Connection $connection): void
    {
        $this->connections[spl_object_id($connection)] = $connection;
    }

    public function unregister(Connection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
    }

    public function wait(float $timeout): void
    {
        $this->selectAndDrain($timeout);
    }

    private function selectAndDrain(float $timeout): void
    {
        foreach (array_values($this->connections) as $connection) {
            try {
                $connection->keepalive();
            } catch (TransportException) {
            }
        }

        /** @var array<int, resource|object> $read */
        $read    = [];
        $indexed = [];

        foreach (array_values($this->connections) as $connection) {
            $socket = $connection->getConsumerSocket();

            if ($socket === null) {
                continue;
            }

            $index           = spl_object_id($connection);
            $read[$index]    = $socket;
            $indexed[$index] = $connection;
        }

        if ($read === []) {
            return;
        }

        $write        = null;
        $except       = null;
        $ready        = $read;
        [$sec, $usec] = $this->splitTimeout($timeout);

        try {
            /** @psalm-suppress InvalidArgument */
            $selected = stream_select($ready, $write, $except, $sec, $usec);
        } catch (Throwable) {
            $selected = false;
        }

        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        if ($selected === false) {
            $selected = 0;
        }

        if ($selected > 0) {
            foreach ($indexed as $index => $connection) {
                if (isset($ready[$index])) {
                    $connection->drainConsumerChannel();
                }
            }

            return;
        }

        foreach ($indexed as $connection) {
            $connection->drainConsumerChannel();
        }
    }

    /** @return array{0: int, 1: int} */
    private function splitTimeout(float $timeout): array
    {
        $seconds      = (int) $timeout;
        $fraction     = $timeout - (float) $seconds;
        $microseconds = (int) round($fraction * 1_000_000.0);

        if ($microseconds >= 1_000_000) {
            $seconds++;
            $microseconds -= 1_000_000;
        }

        if ($seconds < 0) {
            $seconds = 0;
        }

        if ($microseconds < 0) {
            $microseconds = 0;
        }

        return [$seconds, $microseconds];
    }
}

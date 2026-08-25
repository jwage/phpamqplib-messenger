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
 * Multiplexed AMQP wait used by phpamqplib messenger:consume workers.
 *
 * All-phpamqplib workers wait from get(): the first transport of a pass
 * stream_selects every registered socket, later transports only drain.
 * Mixed workers wait from AmqpWorkerListener after every receiver has been
 * polled (coalesce disabled) so Doctrine/Redis/etc. stay in the loop.
 */
class ConsumerWaitCoordinator
{
    /** @var array<int, Connection> */
    private array $connections = [];

    private bool $waitedThisPass = false;

    public function register(Connection $connection): void
    {
        $this->connections[spl_object_id($connection)] = $connection;
    }

    public function unregister(Connection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
    }

    public function reset(): void
    {
        $this->waitedThisPass = false;
    }

    public function wait(float $timeout, bool $coalesce = true): void
    {
        if ($coalesce && $this->waitedThisPass) {
            foreach (array_values($this->connections) as $connection) {
                $connection->drainConsumerChannel();
            }

            return;
        }

        if ($coalesce) {
            $this->waitedThisPass = true;
        }

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

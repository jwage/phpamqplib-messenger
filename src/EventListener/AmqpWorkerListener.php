<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\EventListener;

use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\TransportException;

use function count;
use function is_callable;
use function is_float;
use function is_int;
use function microtime;
use function min;

/**
 * Coordinates AMQP waits for messenger:consume so idle/SIGINT delay stays
 * close to one wait_timeout instead of scaling with the number of transports.
 *
 * All-phpamqplib workers wait inside get() (coalesced across sockets). That
 * way a delivery is yielded in the same worker iteration and leftover
 * --sleep does not delay the next message. Mixed workers match Symfony's
 * PostgreSqlNotifyOnIdleListener: get() only drains, and the wait happens
 * after every receiver has been polled, capped by --sleep.
 */
class AmqpWorkerListener implements EventSubscriberInterface
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /** @var list<Connection> */
    private array $matchedConnections = [];

    private Connection|null $activeConnection = null;

    private float|null $deadline = null;

    private float|null $sleepCap = null;

    public function __construct(
        private ConsumerWaitCoordinator $coordinator,
    ) {
    }

    public function addConnection(string $transportName, Connection $connection): void
    {
        $this->connections[$transportName] = $connection;
        $connection->setWaitCoordinator($this->coordinator);
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->activeConnection   = null;
        $this->matchedConnections = [];
        $this->deadline           = $this->getWorkerDeadline($event);

        /** @var list<string>|null $queueNames */
        $queueNames = $event->getWorker()->getMetadata()->getQueueNames();
        /** @var list<string> $allTransportNames */
        $allTransportNames = $event->getWorker()->getMetadata()->getTransportNames();

        $matched = [];

        foreach ($allTransportNames as $transportName) {
            $connection = $this->connections[$transportName] ?? null;

            if ($connection === null) {
                continue;
            }

            $matched[$transportName] = $connection;
        }

        // When non-phpamqplib transports are also consumed, cap the idle wait to
        // the worker's sleep duration so those transports are still polled regularly.
        $this->sleepCap = count($matched) < count($allTransportNames)
            ? $this->getWorkerIdleTimeoutSeconds($event)
            : null;

        foreach ($matched as $connection) {
            try {
                if ($this->sleepCap !== null) {
                    $connection->listen($queueNames);
                } else {
                    $connection->startConsumers($queueNames);
                }
            } catch (TransportException) {
                // The next get() retries ensureStarted(); keep the worker running.
            }

            $this->coordinator->register($connection);
            $this->matchedConnections[] = $connection;
            $this->activeConnection   ??= $connection;
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->sleepCap === null) {
            $this->coordinator->reset();

            return;
        }

        $this->waitWhileIdle($event, $this->sleepCap);
    }

    public function onWorkerStopped(WorkerStoppedEvent $_event): void
    {
        foreach ($this->matchedConnections as $connection) {
            $connection->disableExternalWait();
            $connection->unregisterFromWaitCoordinator();
        }

        $this->matchedConnections = [];
        $this->activeConnection   = null;
        $this->deadline           = null;
        $this->sleepCap           = null;
    }

    /** @return array<class-string, string> */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
        ];
    }

    /** @param object $event WorkerStartedEvent, or a stand-in without Symfony 8.1 methods. */
    private function getWorkerDeadline(object $event): float|null
    {
        $getter = [$event, 'getDeadline'];

        if (! is_callable($getter)) {
            return null;
        }

        /** @psalm-suppress MixedAssignment */
        $deadline = $getter();

        return is_float($deadline) ? $deadline : null;
    }

    /**
     * Worker sleep duration in seconds. Symfony 8.1+ exposes this as
     * WorkerStartedEvent::getIdleTimeout() (microseconds). Earlier versions
     * use the Worker default of 1 second.
     *
     * @param object $event WorkerStartedEvent, or a stand-in without Symfony 8.1 methods.
     */
    private function getWorkerIdleTimeoutSeconds(object $event): float
    {
        $getter = [$event, 'getIdleTimeout'];

        if (! is_callable($getter)) {
            return 1.0;
        }

        /** @psalm-suppress MixedAssignment */
        $idleTimeout = $getter();

        if (! is_int($idleTimeout) && ! is_float($idleTimeout)) {
            return 1.0;
        }

        /** @psalm-suppress InvalidOperand */
        return $idleTimeout / 1_000_000.0;
    }

    private function waitWhileIdle(WorkerRunningEvent $event, float $sleepCap): void
    {
        if (! $event->isWorkerIdle() || $this->activeConnection === null) {
            return;
        }

        $timeout = $this->activeConnection->getWaitTimeout();

        if ($this->deadline !== null) {
            $timeout = min($timeout, $this->deadline - microtime(true));
        }

        $timeout = min($timeout, $sleepCap);

        if ($timeout <= 0) {
            return;
        }

        $this->activeConnection->waitForDeliveries($timeout, coalesce: false);
    }
}

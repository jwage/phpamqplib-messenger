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

use function count;
use function method_exists;
use function microtime;
use function min;

/**
 * When the worker is idle, blocks on AMQP sockets instead of polling.
 *
 * Matches Symfony's PostgreSqlNotifyOnIdleListener: get() stays non-blocking
 * once the worker starts, and the wait happens after every receiver has been
 * checked. Workers that also consume non-phpamqplib transports cap that wait
 * to --sleep so those transports keep being polled.
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
            $connection->listen($queueNames);
            $this->coordinator->register($connection);
            $this->matchedConnections[] = $connection;
            $this->activeConnection   ??= $connection;
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (! $event->isWorkerIdle() || $this->activeConnection === null) {
            return;
        }

        $timeout = $this->activeConnection->getWaitTimeout();

        if ($this->deadline !== null) {
            $timeout = min($timeout, $this->deadline - microtime(true));

            if ($timeout <= 0) {
                return;
            }
        }

        $timeout = min($timeout, $this->sleepCap ?? $timeout);

        if ($timeout <= 0) {
            return;
        }

        $this->activeConnection->waitForDeliveries($timeout);
    }

    public function onWorkerStopped(WorkerStoppedEvent $_event): void
    {
        foreach ($this->matchedConnections as $connection) {
            $connection->disableExternalWait();
            $this->coordinator->unregister($connection);
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

    private function getWorkerDeadline(WorkerStartedEvent $event): float|null
    {
        if (! method_exists($event, 'getDeadline')) {
            return null;
        }

        return $event->getDeadline();
    }

    /**
     * Worker sleep duration in seconds. Symfony 8.1+ exposes this as
     * WorkerStartedEvent::getIdleTimeout() (microseconds). Earlier versions
     * use the Worker default of 1 second.
     */
    private function getWorkerIdleTimeoutSeconds(WorkerStartedEvent $event): float
    {
        if (! method_exists($event, 'getIdleTimeout')) {
            return 1.0;
        }

        return (float) $event->getIdleTimeout() / 1_000_000.0;
    }
}

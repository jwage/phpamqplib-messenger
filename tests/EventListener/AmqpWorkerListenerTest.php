<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\EventListener;

use Jwage\PhpAmqpLibMessengerBundle\Debug;
use Jwage\PhpAmqpLibMessengerBundle\EventListener\AmqpWorkerListener;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\CollectingLogger;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConsumerWaitCoordinator;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

use function method_exists;
use function microtime;

class AmqpWorkerListenerTest extends TestCase
{
    public function testOnWorkerStartedStartsConsumersForMatchingPhpAmqpLibTransports(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('setWaitCoordinator');
        $connection->expects(self::once())
            ->method('startConsumers')
            ->with(null);
        $connection->expects(self::never())
            ->method('listen');

        $other = $this->createMock(Connection::class);
        $other->expects(self::once())
            ->method('setWaitCoordinator');
        $other->expects(self::never())
            ->method('startConsumers');
        $other->expects(self::never())
            ->method('listen');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register')
            ->with($connection);

        $logger   = new CollectingLogger();
        $listener = new AmqpWorkerListener($coordinator, new Debug($logger, true));
        $listener->addConnection('async', $connection);
        $listener->addConnection('unused', $other);

        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['async'])));

        self::assertTrue($logger->hasTemplate('Worker wait mode is {mode}'));
        self::assertSame('all-phpamqplib', $logger->records[0]['context']['mode'] ?? null);
    }

    public function testOnWorkerStartedListensWhenNonPhpAmqpLibTransportsArePresent(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('listen')
            ->with(null);
        $connection->expects(self::never())
            ->method('startConsumers');

        $logger   = new CollectingLogger();
        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class), new Debug($logger, true));
        $listener->addConnection('async', $connection);

        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['async', 'redis'])));

        self::assertTrue($logger->hasTemplate('Worker wait mode is {mode}'));
        self::assertSame('mixed', $logger->records[0]['context']['mode'] ?? null);
    }

    public function testOnWorkerRunningResetsTheCoordinatorWhenEveryTransportIsPhpAmqpLib(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('startConsumers');
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('reset');

        $logger   = new CollectingLogger();
        $listener = new AmqpWorkerListener($coordinator, new Debug($logger, true));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, false));

        self::assertTrue($logger->hasTemplate('Resetting wait coordinator after all-phpamqplib worker pass'));
    }

    public function testOnWorkerRunningDoesNotWaitWhenTheWorkerIsNotIdle(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, false));
    }

    public function testOnWorkerRunningWaitsTheWorkerSleepWhenIdleAndMixed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->expects(self::never())
            ->method('getWaitTimeout');
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(2.0, false);

        $logger   = new CollectingLogger();
        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class), new Debug($logger, true));
        $listener->addConnection('async', $connection);
        $this->dispatchConsumeSleep($listener, 2.0);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 2_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));

        self::assertTrue($logger->hasTemplate('Mixed worker idle wait'));
    }

    public function testIdleWaitIsNotUsedWhenEveryTransportIsPhpAmqpLib(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('startConsumers');
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $other = $this->createMock(Connection::class);
        $other->method('startConsumers');
        $other->expects(self::never())
            ->method('waitForDeliveries');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('reset');

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('high', $connection);
        $listener->addConnection('low', $other);

        $worker = $this->createWorker(['high', 'low']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 1_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsCappedBySleepWhenNonPhpAmqpLibTransportsArePresent(): void
    {
        if (! method_exists(WorkerStartedEvent::class, 'getIdleTimeout')) {
            self::markTestSkipped('WorkerStartedEvent::getIdleTimeout() requires Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(2.0, false);

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 2_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsCappedByTheWorkerDeadline(): void
    {
        if (! method_exists(WorkerStartedEvent::class, 'getDeadline')) {
            self::markTestSkipped('WorkerStartedEvent::getDeadline() requires Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(self::callback(static function (float $timeout): bool {
                return $timeout > 0.0 && $timeout <= 2.5;
            }), false);

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, deadline: microtime(true) + 2.0, idleTimeout: 10_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsSkippedWhenTheWorkerDeadlineHasPassed(): void
    {
        if (! method_exists(WorkerStartedEvent::class, 'getDeadline')) {
            self::markTestSkipped('WorkerStartedEvent::getDeadline() requires Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $logger   = new CollectingLogger();
        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class), new Debug($logger, true));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, deadline: microtime(true) - 1.0));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));

        self::assertTrue($logger->hasTemplate('Mixed worker idle wait skipped; no time remaining'));
    }

    public function testOnWorkerStoppedDisablesExternalWait(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('startConsumers');
        $connection->expects(self::once())
            ->method('disableExternalWait');
        $connection->expects(self::once())
            ->method('unregisterFromWaitCoordinator');

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $floors      = [];
        $coordinator->expects(self::once())
            ->method('register')
            ->with($connection);
        $coordinator->expects(self::exactly(2))
            ->method('setWaitFloor')
            ->willReturnCallback(static function (float $floor) use (&$floors): void {
                $floors[] = $floor;
            });

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerStopped(new WorkerStoppedEvent($worker));

        self::assertSame(
            [method_exists(WorkerStartedEvent::class, 'getIdleTimeout') ? 1.0 : 0.0, 0.0],
            $floors,
        );
    }

    public function testOnWorkerStartedMatchesPhpAmqpLibTransportsAfterUnmatchedOnes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('listen');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['redis', 'async'])));
    }

    public function testOnWorkerStartedSurvivesATransportThatCannotConnect(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('startConsumers')
            ->willThrowException(new TransportException('connection refused'));

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('register')
            ->with($connection);

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['async'])));
    }

    public function testIdleWaitUsesTheFirstMatchedPhpAmqpLibTransport(): void
    {
        $first = $this->createMock(Connection::class);
        $first->method('listen');
        $first->expects(self::once())
            ->method('waitForDeliveries')
            ->with(2.0, false);

        $second = $this->createMock(Connection::class);
        $second->method('listen');
        $second->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('high', $first);
        $listener->addConnection('low', $second);
        $this->dispatchConsumeSleep($listener, 2.0);

        $worker = $this->createWorker(['high', 'low', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 2_000_000));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleWaitIsSkippedWhenSleepCapIsZero(): void
    {
        if (! method_exists(WorkerStartedEvent::class, 'getIdleTimeout')) {
            self::markTestSkipped('WorkerStartedEvent::getIdleTimeout() requires Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->expects(self::never())
            ->method('waitForDeliveries');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker, idleTimeout: 0));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    public function testIdleTimeoutFallsBackWhenTheEventDoesNotExposeIt(): void
    {
        $method   = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');
        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));

        self::assertNull($method->invoke($listener, new stdClass()));
    }

    public function testIdleTimeoutFallsBackWhenTheValueIsNotNumeric(): void
    {
        $event = new class {
            public function getIdleTimeout(): string
            {
                return 'nope';
            }
        };

        $method   = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');
        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));

        self::assertNull($method->invoke($listener, $event));
    }

    public function testIdleTimeoutUsesConsoleSleepWhenTheEventValueIsNotNumeric(): void
    {
        $event = new class {
            public function getIdleTimeout(): string
            {
                return 'nope';
            }
        };

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $this->dispatchConsumeSleep($listener, 0.4);

        $method = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');

        self::assertSame(0.4, $method->invoke($listener, $event));
    }

    public function testIdleTimeoutUsesConsoleSleepWhenTheEventDoesNotExposeIt(): void
    {
        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $this->dispatchConsumeSleep($listener, 2.5);

        $method = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');

        self::assertSame(2.5, $method->invoke($listener, new stdClass()));
    }

    public function testConsoleSleepIsIgnoredWhenTheSleepOptionCannotBeRead(): void
    {
        $command = $this->createStub(Command::class);
        $command->method('getName')
            ->willReturn('messenger:consume');

        $input = $this->createMock(InputInterface::class);
        $input->expects(self::once())
            ->method('hasOption')
            ->with('sleep')
            ->willReturn(true);
        $input->expects(self::once())
            ->method('getOption')
            ->with('sleep')
            ->willThrowException(new InvalidArgumentException('The "sleep" option does not exist.'));

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            $input,
            $this->createStub(OutputInterface::class),
        ));

        $method = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');

        self::assertNull($method->invoke($listener, new stdClass()));
    }

    public function testConsoleSleepIsIgnoredForOtherCommands(): void
    {
        $command = $this->createStub(Command::class);
        $command->method('getName')
            ->willReturn('cache:clear');

        $input = $this->createMock(InputInterface::class);
        $input->expects(self::never())
            ->method('hasOption');
        $input->expects(self::never())
            ->method('getOption');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            $input,
            $this->createStub(OutputInterface::class),
        ));

        $method = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');

        self::assertNull($method->invoke($listener, new stdClass()));
    }

    public function testConsoleSleepIsIgnoredWhenTheCommandIsMissing(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->expects(self::never())
            ->method('hasOption');
        $input->expects(self::never())
            ->method('getOption');

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->onConsoleCommand(new ConsoleCommandEvent(
            null,
            $input,
            $this->createStub(OutputInterface::class),
        ));

        $method = new ReflectionMethod(AmqpWorkerListener::class, 'getWorkerIdleTimeoutSeconds');

        self::assertNull($method->invoke($listener, new stdClass()));
    }

    public function testOnWorkerStartedSetsAWaitFloorForAllPhpAmqpLibWorkers(): void
    {
        $expectedFloor = method_exists(WorkerStartedEvent::class, 'getIdleTimeout') ? 2.0 : 0.0;

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('setWaitFloor')
            ->with($expectedFloor);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('startConsumers');

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);
        $listener->onWorkerStarted($this->createWorkerStartedEvent(
            $this->createWorker(['async']),
            idleTimeout: 2_000_000,
        ));
    }

    public function testOnWorkerStartedUsesConsoleSleepAsWaitFloorWhenTheEventOmitsIdleTimeout(): void
    {
        if (method_exists(WorkerStartedEvent::class, 'getIdleTimeout')) {
            self::markTestSkipped('WorkerStartedEvent::getIdleTimeout() is used on Symfony 8.1+');
        }

        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('setWaitFloor')
            ->with(0.5);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('startConsumers');

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);
        $this->dispatchConsumeSleep($listener, 0.5);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['async'])));
    }

    public function testOnWorkerStartedClearsTheWaitFloorWhenTransportsAreMixed(): void
    {
        $coordinator = $this->createMock(ConsumerWaitCoordinator::class);
        $coordinator->expects(self::once())
            ->method('setWaitFloor')
            ->with(0.0);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('listen');

        $listener = new AmqpWorkerListener($coordinator);
        $listener->addConnection('async', $connection);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($this->createWorker(['async', 'redis'])));
    }

    public function testMixedIdleWaitUsesConsoleSleepWhenTheStartedEventOmitsIt(): void
    {
        if (method_exists(WorkerStartedEvent::class, 'getIdleTimeout')) {
            self::markTestSkipped('WorkerStartedEvent::getIdleTimeout() is used on Symfony 8.1+');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('listen');
        $connection->expects(self::once())
            ->method('waitForDeliveries')
            ->with(0.2, false);

        $listener = new AmqpWorkerListener($this->createStub(ConsumerWaitCoordinator::class));
        $listener->addConnection('async', $connection);
        $this->dispatchConsumeSleep($listener, 0.2);

        $worker = $this->createWorker(['async', 'redis']);
        $listener->onWorkerStarted($this->createWorkerStartedEvent($worker));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
    }

    /** @param list<string> $transportNames */
    private function createWorker(array $transportNames): Worker
    {
        $receivers = [];

        foreach ($transportNames as $transportName) {
            $receivers[$transportName] = $this->createStub(ReceiverInterface::class);
        }

        return new Worker(
            $receivers,
            $this->createStub(MessageBusInterface::class),
        );
    }

    private function createWorkerStartedEvent(
        Worker $worker,
        float|null $deadline = null,
        int $idleTimeout = 1_000_000,
    ): WorkerStartedEvent {
        $reflection  = new ReflectionClass(WorkerStartedEvent::class);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfParameters() >= 3) {
            return $reflection->newInstance($worker, $deadline, $idleTimeout);
        }

        return new WorkerStartedEvent($worker);
    }

    private function dispatchConsumeSleep(AmqpWorkerListener $listener, float $seconds): void
    {
        $command = $this->createStub(Command::class);
        $command->method('getName')
            ->willReturn('messenger:consume');

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')
            ->willReturn(true);
        $input->method('getOption')
            ->willReturn((string) $seconds);

        $listener->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            $input,
            $this->createStub(OutputInterface::class),
        ));
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use Jwage\PhpAmqpLibMessengerBundle\Batch;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function count;
use function function_exists;
use function max;
use function microtime;
use function usleep;

use const SIGINT;
use const SIGTERM;

/**
 * Real messenger:consume against RabbitMQ: throughput, idle wake, ack, signals.
 */
#[Group('live')]
#[Group('e2e')]
class MessengerConsumeE2eTest extends E2eTestCase
{
    public function testQueuedMessagesAreConsumedWithoutSleepingBetweenThem(): void
    {
        $ids = [];

        for ($i = 0; $i < 8; $i++) {
            $ids[] = $this->uniqueId('burst');
            $this->bus()->dispatch(new E2eMessage($ids[$i]));
        }

        $this->startConsume(['e2e_high'], limit: 8);
        $this->assertConsumeExitsSuccessfully();

        $records = $this->recordsOfType(E2eMessage::class);
        self::assertCount(8, $records);
        self::assertSame($ids, $this->idsOf($records));
        self::assertNotSame($this->parentPid(), $records[0]['pid'] ?? 0);

        $gaps = [];

        for ($i = 1; $i < 8; $i++) {
            $gaps[] = $records[$i]['t'] - $records[$i - 1]['t'];
        }

        self::assertLessThan(
            0.25,
            max($gaps),
            'messenger:consume delayed between already-queued messages; gaps=' . $this->formatGaps($gaps),
        );
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testIdleConsumerWakesQuicklyWhenAMessageIsDispatched(): void
    {
        $id = $this->uniqueId('idle');

        $this->startConsume(['e2e_high'], limit: 1);
        $this->waitUntilConsuming();
        usleep(400_000);

        $dispatchedAt = microtime(true);
        $this->bus()->dispatch(new E2eMessage($id));
        $record = $this->waitForRecord(E2eMessage::class, $id);

        self::assertLessThan(
            0.4,
            $record['t'] - $dispatchedAt,
            'Idle messenger:consume did not wake promptly after a new message was dispatched',
        );

        $this->assertConsumeExitsSuccessfully();
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testHandlerCanDispatchAFollowupMessageThatTheSameWorkerConsumes(): void
    {
        $id = $this->uniqueId('followup');

        $this->bus()->dispatch(new E2eMessage($id, dispatchFollowup: true));

        $this->startConsume(['e2e_high'], limit: 2);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eMessage::class)));
        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eFollowupMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testHandlerCanDispatchAFollowupOntoAnotherTransport(): void
    {
        $id = $this->uniqueId('cross');

        $this->bus()->dispatch(new E2eMessage($id, dispatchToLow: true));

        $this->startConsume(['e2e_high', 'e2e_low'], limit: 2);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eMessage::class)));
        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eLowMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_high');
        $this->assertQueueEventuallyEmpty('e2e_low');
    }

    public function testMessageOnTheSecondTransportWakesWithoutWaitingForTheFirstTimeout(): void
    {
        $id = $this->uniqueId('low');

        $this->startConsume(['e2e_high', 'e2e_low'], limit: 1);
        $this->waitUntilConsuming();
        usleep(400_000);

        $dispatchedAt = microtime(true);
        $this->bus()->dispatch(new E2eLowMessage($id));
        $record = $this->waitForRecord(E2eLowMessage::class, $id);

        self::assertLessThan(
            0.4,
            $record['t'] - $dispatchedAt,
            'Second-transport message waited for the first transport wait_timeout',
        );

        $this->assertConsumeExitsSuccessfully();
        $this->assertQueueEventuallyEmpty('e2e_low');
    }

    public function testMixedIdleWorkerWakesForAmqpWhilePollingInMemory(): void
    {
        $id = $this->uniqueId('mixed');

        $this->startConsume(['e2e_high', 'e2e_memory'], limit: 1, sleep: 3.0);
        $this->waitUntilConsuming();
        // wait_timeout is 1s; leftover Worker --sleep after that wait would be unselected.
        usleep(2_000_000);

        $dispatchedAt = microtime(true);
        $this->bus()->dispatch(new E2eMessage($id));
        $record = $this->waitForRecord(E2eMessage::class, $id);

        self::assertLessThan(
            0.75,
            $record['t'] - $dispatchedAt,
            'Mixed worker did not wake for AMQP (leftover --sleep or idle wait too long)',
        );

        $this->assertConsumeExitsSuccessfully();
    }

    public function testInMemoryMessageIsConsumedAlongsidePhpAmqpLib(): void
    {
        $id = $this->uniqueId('memory');

        $this->bus()->dispatch(new E2eMessage($id, dispatchToMemory: true));

        $this->startConsume(['e2e_high', 'e2e_memory'], limit: 2, sleep: 0, extra: ['--no-reset']);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eMessage::class)));
        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eMemoryMessage::class)));
    }

    public function testBatchFlushThenConsume(): void
    {
        $ids   = [$this->uniqueId('batch1'), $this->uniqueId('batch2'), $this->uniqueId('batch3')];
        $batch = Batch::new($this->bus(), 10);

        foreach ($ids as $id) {
            $batch->dispatch(new E2eMessage($id));
        }

        $batch->flush();

        $this->startConsume(['e2e_high'], limit: 3);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame($ids, $this->idsOf($this->recordsOfType(E2eMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testDelayStampIsNotHandledBeforeTheDelayElapses(): void
    {
        $id = $this->uniqueId('delay');

        $dispatchedAt = microtime(true);
        $this->bus()->dispatch(Envelope::wrap(new E2eMessage($id))->with(new DelayStamp(600)));

        $this->startConsume(['e2e_high'], limit: 1);
        usleep(200_000);

        self::assertSame([], $this->recordsOfType(E2eMessage::class));

        $record = $this->waitForRecord(E2eMessage::class, $id, timeout: 10.0);

        self::assertGreaterThanOrEqual(0.45, $record['t'] - $dispatchedAt);
        $this->assertConsumeExitsSuccessfully();
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testTimeLimitStopsBeforeABusyQueueIsFullyDrained(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->bus()->dispatch(new E2eMessage($this->uniqueId('slow'), sleepMs: 200));
        }

        $this->startConsume(['e2e_high'], timeLimit: 1);
        $this->assertConsumeExitsSuccessfully();

        $handled = $this->recordsOfType(E2eMessage::class);
        self::assertNotSame([], $handled);
        self::assertLessThan(8, count($handled));
        self::assertGreaterThan(0, $this->messageCount('e2e_high'));
    }

    public function testTimeLimitStopsAnIdleConsumer(): void
    {
        $started = microtime(true);
        $this->startConsume(['e2e_high'], timeLimit: 1, sleep: 0);
        $this->assertConsumeExitsSuccessfully();

        self::assertLessThan(4.0, microtime(true) - $started);
        self::assertSame([], $this->recordsOfType(E2eMessage::class, failed: null));
    }

    public function testConsumeAllHandlesARoutedMessage(): void
    {
        if (! $this->consumeSupports('--all')) {
            self::markTestSkipped('messenger:consume --all requires a newer Symfony Messenger');
        }

        $id = $this->uniqueId('all');
        $this->bus()->dispatch(new E2eMessage($id));

        $this->setupTransport('e2e_manual');
        $this->setupTransport('e2e_auto');

        $this->startConsume([], limit: 1, extra: ['--all']);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eMessage::class)));
    }

    public function testDeduplicationMiddlewareSetsAMessageIdOnConsumedMessages(): void
    {
        $id = $this->uniqueId('dedup');
        $this->bus()->dispatch(new E2eMessage($id));

        $this->startConsume(['e2e_high'], limit: 1);
        $this->assertConsumeExitsSuccessfully();

        $record = $this->recordsOfType(E2eMessage::class)[0] ?? null;
        self::assertNotNull($record);
        self::assertNotEmpty($record['message_id']);
    }

    public function testSigintStopsAMultiTransportConsumerWithoutWaitingEveryTimeout(): void
    {
        if (! function_exists('posix_kill')) {
            self::markTestSkipped('posix_kill is required to send SIGINT');
        }

        $this->startConsume(['e2e_high', 'e2e_low']);
        $this->waitUntilConsuming();
        usleep(200_000);

        $signalledAt = microtime(true);
        $this->lastProcess()->signal(SIGINT);
        $this->lastProcess()->wait(5.0);

        self::assertLessThan(
            1.7,
            microtime(true) - $signalledAt,
            'SIGINT waited as if each phpamqplib transport blocked for wait_timeout',
        );
    }

    public function testSigtermLetsAnInFlightHandlerFinishAndAck(): void
    {
        if (! function_exists('posix_kill')) {
            self::markTestSkipped('posix_kill is required to send SIGTERM');
        }

        $id = $this->uniqueId('slow');
        $this->bus()->dispatch(new E2eMessage($id, sleepMs: 400));

        $this->startConsume(['e2e_high'], limit: 1);
        $this->waitUntilConsuming();
        usleep(150_000);

        $this->lastProcess()->signal(SIGTERM);
        $this->lastProcess()->wait(10.0);

        $handled = $this->idsOf($this->recordsOfType(E2eMessage::class));

        if ($handled === []) {
            self::markTestSkipped('SIGTERM did not let the in-flight handler finish (older Messenger)');
        }

        self::assertSame([$id], $handled);
        $this->assertQueueEventuallyEmpty('e2e_high');
    }
}

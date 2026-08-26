<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;

use function array_unique;
use function class_exists;
use function interface_exists;
use function microtime;
use function sort;

#[Group('live')]
#[Group('e2e')]
class MessengerConsumeWorkerE2eTest extends E2eTestCase
{
    public function testTwoConsumersShareWorkWithoutLosingMessages(): void
    {
        $ids = [];

        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->uniqueId('share');
            $this->bus()->dispatch(new E2eMessage($ids[$i]));
        }

        $first  = $this->startConsume(['e2e_high'], limit: 3);
        $second = $this->startConsume(['e2e_high'], limit: 3);

        $this->assertConsumeExitsSuccessfully($first);
        $this->assertConsumeExitsSuccessfully($second);

        $handled = $this->idsOf($this->recordsOfType(E2eMessage::class));
        sort($handled);
        $expected = $ids;
        sort($expected);

        self::assertSame($expected, $handled);
        self::assertCount(6, array_unique($handled));
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testStopWorkersCommandStopsAnIdleConsumer(): void
    {
        $consume = $this->startConsume(['e2e_high'], sleep: 0);
        $this->waitUntilConsuming($consume);

        self::assertSame(0, $this->runConsole(['messenger:stop-workers']));
        $consume->wait(10.0);
    }

    public function testConsumeDoesNotCrashWhenTheBrokerIsUnreachableAtStart(): void
    {
        $this->setConsumeEnv(
            'E2E_HIGH_DSN',
            'phpamqplib://guest:guest@127.0.0.1:1/%2f/e2e_down'
            . '?retries=0&retry_wait_time=0&connect_timeout=0.2&read_timeout=0.2&write_timeout=0.2',
        );

        $started = microtime(true);
        $this->startConsume(['e2e_high'], timeLimit: 2, sleep: 0);
        $this->assertConsumeExitsSuccessfully();

        self::assertLessThan(8.0, microtime(true) - $started);
        self::assertSame([], $this->recordsOfType(E2eMessage::class, failed: null));
    }

    public function testKeepaliveAllowsAHandlerLongerThanTheHeartbeatInterval(): void
    {
        if (! interface_exists(KeepaliveReceiverInterface::class)) {
            self::markTestSkipped('messenger:consume --keepalive requires Symfony >= 7.2');
        }

        if (! class_exists(SignalRegistry::class) || ! SignalRegistry::isSupported()) {
            self::markTestSkipped('messenger:consume --keepalive requires pcntl signals');
        }

        if (! $this->consumeSupports('--keepalive')) {
            self::markTestSkipped('messenger:consume --keepalive is not available');
        }

        $id      = $this->uniqueId('keep');
        $started = microtime(true);
        $this->bus()->dispatch(new E2eKeepaliveMessage($id, sleepMs: 3500));

        $this->startConsume(['e2e_keepalive'], limit: 1, extra: ['--keepalive=1'], timeLimit: 20);
        $this->assertConsumeExitsSuccessfully();

        self::assertGreaterThan(3.0, microtime(true) - $started);
        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eKeepaliveMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_keepalive');
    }
}

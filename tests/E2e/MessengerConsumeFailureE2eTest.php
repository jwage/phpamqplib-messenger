<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use Throwable;

use function array_key_last;

class MessengerConsumeFailureE2eTest extends E2eTestCase
{
    public function testHandlerFailureIsRetriedThenSucceeds(): void
    {
        $id = $this->uniqueId('retry');
        $this->bus()->dispatch(new E2eMessage($id, failUntilAttempt: 2));

        $this->startConsume(['e2e_high'], limit: 2, extra: ['--failure-limit=5']);
        $this->assertConsumeExitsSuccessfully();

        $failed = $this->recordsOfType(E2eMessage::class, failed: true);
        self::assertNotSame([], $failed);
        self::assertTrue($failed[0]['will_retry'] ?? false);
        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testUnrecoverableFailureLandsOnTheFailureTransport(): void
    {
        $id = $this->uniqueId('poison');
        $this->bus()->dispatch(new E2eMessage($id, unrecoverable: true));

        $this->startConsume(['e2e_high'], extra: ['--failure-limit=1'], timeLimit: 15);
        $this->assertConsumeExitsSuccessfully();

        $failed = $this->recordsOfType(E2eMessage::class, failed: true);
        self::assertNotSame([], $failed);
        self::assertFalse($failed[0]['will_retry'] ?? true);
        self::assertGreaterThanOrEqual(1, $this->messageCount('e2e_failed'));
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testMaxRetriesLandOnTheFailureTransport(): void
    {
        $id = $this->uniqueId('max-retry');
        $this->bus()->dispatch(new E2eMessage($id, failUntilAttempt: 99));

        $this->startConsume(['e2e_high'], extra: ['--failure-limit=4'], timeLimit: 15);
        $this->assertConsumeExitsSuccessfully();

        $failed = $this->recordsOfType(E2eMessage::class, failed: true);
        self::assertNotSame([], $failed);
        $lastFailedKey = array_key_last($failed);
        self::assertNotNull($lastFailedKey);
        self::assertFalse($failed[$lastFailedKey]['will_retry']);
        self::assertGreaterThanOrEqual(1, $this->messageCount('e2e_failed'));
        $this->assertQueueEventuallyEmpty('e2e_high');
    }

    public function testUndecodableMessageIsNackedAndDoesNotBlockLaterMessages(): void
    {
        $this->amqpTransport('e2e_high')->getConnection()->publish(body: 'this is not a serialized messenger envelope');

        $this->startConsume(['e2e_high'], extra: ['--failure-limit=5'], timeLimit: 5);

        try {
            $this->lastProcess()->wait(10.0);
        } catch (Throwable) {
        }

        $this->assertQueueEventuallyEmpty('e2e_high');

        $id = $this->uniqueId('after-poison');
        $this->bus()->dispatch(new E2eMessage($id));

        $this->startConsume(['e2e_high'], limit: 1);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_high');
    }
}

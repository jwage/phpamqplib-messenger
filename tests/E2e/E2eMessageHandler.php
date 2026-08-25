<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use RuntimeException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

use function max;
use function microtime;
use function usleep;

class E2eMessageHandler
{
    public function __construct(
        private E2eRecordStore $recordStore,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(E2eMessage $message): void
    {
        $this->applySideEffects($message);
    }

    public function handleFollowup(E2eFollowupMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    public function handleLow(E2eLowMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    public function handleTx(E2eTxMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    public function handleRouted(E2eRoutedMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    public function handleGreedy(E2eGreedyMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    public function handleManual(E2eManualMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    public function handleKeepalive(E2eKeepaliveMessage $message): void
    {
        $this->sleepMs($message->sleepMs);
    }

    public function handleMemory(E2eMemoryMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    public function handleSsl(E2eSslMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    public function handleAuto(E2eAutoMessage $_message): void
    {
        // Handling is recorded by E2eConsumeEventSubscriber.
    }

    private function applySideEffects(E2eMessage $message): void
    {
        if ($message->failUntilAttempt > 0) {
            $attempts = $this->recordStore->incrementAttempts($message->id);

            if ($attempts < $message->failUntilAttempt) {
                throw new RuntimeException('E2E retry ' . $attempts);
            }
        }

        if ($message->unrecoverable) {
            throw new UnrecoverableMessageHandlingException('E2E poison');
        }

        if ($message->sleepMs > 0) {
            $this->sleepMs($message->sleepMs);
        }

        if ($message->dispatchFollowup) {
            $this->bus->dispatch(new E2eFollowupMessage($message->id));
        }

        if ($message->dispatchToLow) {
            $this->bus->dispatch(new E2eLowMessage($message->id));
        }

        if ($message->dispatchToMemory) {
            $this->bus->dispatch(new E2eMemoryMessage($message->id));
        }
    }

    private function sleepMs(int $sleepMs): void
    {
        $deadline = microtime(true) + ((float) $sleepMs / 1000.0);

        while (microtime(true) < $deadline) {
            usleep(max(1, (int) (($deadline - microtime(true)) * 1_000_000.0)));
        }
    }
}

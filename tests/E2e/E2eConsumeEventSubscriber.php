<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

use function get_object_vars;
use function getmypid;
use function is_string;
use function method_exists;
use function property_exists;

class E2eConsumeEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private E2eRecordStore $recordStore,
    ) {
    }

    /** @return array<class-string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onHandled',
            WorkerMessageFailedEvent::class => 'onFailed',
        ];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->record($event->getEnvelope()->getMessage(), $event, failed: false);
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->record($event->getEnvelope()->getMessage(), $event, failed: true);
    }

    private function record(
        object $message,
        WorkerMessageHandledEvent|WorkerMessageFailedEvent $event,
        bool $failed,
    ): void {
        $id = property_exists($message, 'id') ? $this->stringId($message) : $message::class;

        $stamp     = $event->getEnvelope()->last(AmqpReceivedStamp::class);
        $willRetry = false;

        if ($event instanceof WorkerMessageFailedEvent && method_exists($event, 'willRetry')) {
            $willRetry = $event->willRetry();
        }

        $this->recordStore->record($message::class, $id, [
            'failed' => $failed,
            'will_retry' => $willRetry,
            'pid' => getmypid(),
            'transport' => method_exists($event, 'getReceiverName') ? $event->getReceiverName() : '',
            'queue' => $stamp?->getQueueName(),
            'message_id' => $stamp?->getAmqpEnvelope()?->getMessageId(),
        ]);
    }

    private function stringId(object $message): string
    {
        $vars = get_object_vars($message);

        if (! isset($vars['id']) || ! is_string($vars['id'])) {
            return $message::class;
        }

        return $vars['id'];
    }
}

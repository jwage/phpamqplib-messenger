<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Middleware;

use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use LogicException;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Uid\Uuid;

use function assert;
use function class_exists;
use function is_string;

final class DeduplicationPluginMiddleware implements MiddlewareInterface
{
    /**
     * @throws LogicException
     *
     * @inheritDoc
     */
    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $existingAmqpStamp = $envelope->last(AmqpStamp::class);

        $messageId = $existingAmqpStamp?->getAttributes()['message_id'] ?? null;
        assert(is_string($messageId) || $messageId === null);

        if ($messageId === null) {
            if (! class_exists(Uuid::class)) {
                throw new LogicException('The UID component is required to use the DeduplicationPluginMiddleware if you do not provide a message ID. Try running "composer require symfony/uid".');
            }

            $messageId = Uuid::v4()->toRfc4122();
        }

        /** @var array<string, mixed> $headers */
        $headers = $existingAmqpStamp?->getAttributes()['headers'] ?? [];

        $newAmqpStamp = AmqpStamp::createWithAttributes(
            attributes: [
                'message_id' => $messageId,
                'headers' => [
                    ...$headers,
                    'x-deduplication-header' => $messageId,
                ],
            ],
            previousStamp: $existingAmqpStamp,
        );

        $envelope = $envelope->withoutAll(AmqpStamp::class)->with($newAmqpStamp);

        return $stack->next()->handle($envelope, $stack);
    }
}

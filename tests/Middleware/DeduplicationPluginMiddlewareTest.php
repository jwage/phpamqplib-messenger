<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Middleware;

use Jwage\PhpAmqpLibMessengerBundle\Middleware\DeduplicationPluginMiddleware;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

class DeduplicationPluginMiddlewareTest extends TestCase
{
    public function testHandlePassesThroughReceivedEnvelopesWithoutAddingAmqpStamp(): void
    {
        $envelope = Envelope::wrap(new stdClass())->with(new ReceivedStamp('phpamqplib'));

        $result = $this->handle($envelope);

        self::assertSame($envelope, $result);
        self::assertNull($result->last(AmqpStamp::class));
    }

    public function testHandleGeneratesMessageIdAndDeduplicationHeader(): void
    {
        $result     = $this->handle(Envelope::wrap(new stdClass()));
        $attributes = $result->last(AmqpStamp::class)?->getAttributes() ?? [];
        $messageId  = $attributes['message_id'] ?? null;

        self::assertIsString($messageId);
        self::assertTrue(Uuid::isValid($messageId));
        self::assertSame(
            ['x-deduplication-header' => $messageId],
            $attributes['headers'] ?? null,
        );
    }

    public function testHandleGeneratesAUniqueMessageIdForEachEnvelope(): void
    {
        $firstAttributes  = $this->handle(Envelope::wrap(new stdClass()))->last(AmqpStamp::class)?->getAttributes() ?? [];
        $secondAttributes = $this->handle(Envelope::wrap(new stdClass()))->last(AmqpStamp::class)?->getAttributes() ?? [];

        $first  = $firstAttributes['message_id'] ?? null;
        $second = $secondAttributes['message_id'] ?? null;

        self::assertIsString($first);
        self::assertIsString($second);
        self::assertNotSame($first, $second);
    }

    public function testHandlePreservesExistingMessageIdRoutingKeyAndHeaders(): void
    {
        $envelope = Envelope::wrap(new stdClass())->with(new AmqpStamp(routingKey: 'orders', attributes: [
            'message_id' => 'existing-id',
            'test' => 'abc',
            'headers' => ['x-test' => true],
        ]));

        $result     = $this->handle($envelope);
        $stamp      = $result->last(AmqpStamp::class);
        $attributes = $stamp?->getAttributes() ?? [];

        self::assertSame('orders', $stamp?->getRoutingKey());
        self::assertSame('existing-id', $attributes['message_id'] ?? null);
        self::assertSame('abc', $attributes['test'] ?? null);
        self::assertSame([
            'x-test' => true,
            'x-deduplication-header' => 'existing-id',
        ], $attributes['headers'] ?? null);
        self::assertCount(1, $result->all(AmqpStamp::class));
    }

    public function testHandleOverwritesExistingDeduplicationHeaderWithMessageId(): void
    {
        $envelope = Envelope::wrap(new stdClass())->with(new AmqpStamp(attributes: [
            'message_id' => 'existing-id',
            'headers' => ['x-deduplication-header' => 'old'],
        ]));

        $attributes = $this->handle($envelope)->last(AmqpStamp::class)?->getAttributes() ?? [];

        self::assertSame(['x-deduplication-header' => 'existing-id'], $attributes['headers'] ?? null);
    }

    private function handle(Envelope $envelope): Envelope
    {
        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static fn (Envelope $handled): Envelope => $handled);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::once())
            ->method('next')
            ->willReturn($next);

        return (new DeduplicationPluginMiddleware())->handle($envelope, $stack);
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\Batch;
use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Message\ConfirmMessage;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Message\TransactionMessage;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Wire\AMQPTable;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Traversable;

use function assert;
use function count;
use function sprintf;

class TransportFunctionalTest extends KernelTestCase
{
    private MessageBusInterface $bus;

    private AmqpTransport $confirmsTransport;

    private AmqpTransport $transactionsTransport;

    public function testTransportWithConfirms(): void
    {
        $envelopes = $this->getEnvelopes($this->confirmsTransport, 0);

        self::assertCount(0, $envelopes);

        $message1 = Envelope::wrap(new ConfirmMessage(1))->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));
        $message2 = Envelope::wrap(new ConfirmMessage(2))->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));
        $message3 = Envelope::wrap(new ConfirmMessage(3))->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));

        $messages = [$message1, $message2, $message3];

        $this->dispatchMessages($messages);

        // test we can recover from a reconnect inbetween dispatching and consuming
        $this->confirmsTransport->getConnection()->reconnect();

        $envelopes = $this->getEnvelopes($this->confirmsTransport, 3);

        self::assertCount(3, $envelopes);

        self::assertEquals(1, $envelopes[0]->getMessage()->count);
        self::assertEquals(1, $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);

        self::assertEquals(2, $envelopes[1]->getMessage()->count);
        self::assertEquals(1, $envelopes[1]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[1]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);

        self::assertEquals(3, $envelopes[2]->getMessage()->count);
        self::assertEquals(1, $envelopes[2]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[2]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);
    }

    public function testTransportWithTransactions(): void
    {
        $envelopes = $this->getEnvelopes($this->transactionsTransport, 0);

        self::assertCount(0, $envelopes);

        $message1 = Envelope::wrap(new TransactionMessage(1))->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));
        $message2 = Envelope::wrap(new TransactionMessage(2))->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));
        $message3 = Envelope::wrap(new TransactionMessage(3))->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));

        $messages = [$message1, $message2, $message3];

        $this->dispatchMessages($messages);

        // test we can recover from a reconnect inbetween dispatching and consuming
        $this->transactionsTransport->getConnection()->reconnect();

        $envelopes = $this->getEnvelopes($this->transactionsTransport, 3);

        self::assertCount(3, $envelopes);

        self::assertEquals(1, $envelopes[0]->getMessage()->count);
        self::assertEquals(1, $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);

        self::assertEquals(2, $envelopes[1]->getMessage()->count);
        self::assertEquals(1, $envelopes[1]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[1]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);

        self::assertEquals(3, $envelopes[2]->getMessage()->count);
        self::assertEquals(1, $envelopes[2]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[2]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);
    }

    public function testDispatchWithoutBatches(): void
    {
        $message1 = new ConfirmMessage(1);
        $message2 = new ConfirmMessage(2);
        $message3 = new ConfirmMessage(3);

        $this->bus->dispatch($message1);
        $this->bus->dispatch($message2);
        $this->bus->dispatch($message3);

        $envelopes = $this->getEnvelopes($this->confirmsTransport, 3);

        self::assertCount(3, $envelopes);

        self::assertEquals($message1, $envelopes[0]->getMessage());
        self::assertEquals($message2, $envelopes[1]->getMessage());
        self::assertEquals($message3, $envelopes[2]->getMessage());
    }

    public function testMessageId(): void
    {
        $message = Envelope::wrap(new ConfirmMessage(1))->with(new AmqpStamp(attributes: ['message_id' => '123']));

        $this->bus->dispatch($message);

        $envelopes = $this->getEnvelopes($this->confirmsTransport, 1);

        self::assertEquals('123', $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getMessageId());
        self::assertEquals('123', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testDeduplicationPluginMiddlewareGeneratesMessageIdAndHeader(): void
    {
        $message = new ConfirmMessage(1);

        $envelope = $this->bus->dispatch($message);

        $attributes = $envelope->last(AmqpStamp::class)?->getAttributes() ?? [];

        $messageId = $attributes['message_id'] ?? null;

        self::assertSame([
            'message_id' => $messageId,
            'headers' => ['x-deduplication-header' => $messageId],
        ], $attributes);

        self::assertNotNull($messageId);
        self::assertSame($messageId, $envelope->last(TransportMessageIdStamp::class)?->getId());

        $envelopes = $this->getEnvelopes($this->confirmsTransport, 1);

        self::assertSame($messageId, $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getMessageId());

        self::assertSame(['x-deduplication-header' => $messageId], $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders());

        self::assertEquals([
            'content_type' => 'text/plain',
            'application_headers' => new AMQPTable(['x-deduplication-header' => $messageId]),
            'delivery_mode' => 2,
            'message_id' => $messageId,
        ], $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getAttributes());

        self::assertSame($messageId, $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testDeduplicationPluginMiddlewareMaintainsExistingAmqpStampAttributes(): void
    {
        $message = Envelope::wrap(new ConfirmMessage(1))->with(new AmqpStamp(attributes: [
            'message_id' => '123',
            'test' => 'abc',
            'headers' => ['x-test' => true],
        ]));

        $envelope = $this->bus->dispatch($message);

        $attributes = $envelope->last(AmqpStamp::class)?->getAttributes() ?? [];

        $messageId = $attributes['message_id'] ?? null;

        self::assertSame([
            'message_id' => $messageId,
            'test' => 'abc',
            'headers' => [
                'x-test' => true,
                'x-deduplication-header' => $messageId,
            ],
        ], $attributes);

        self::assertNotNull($messageId);
        self::assertSame($messageId, $envelope->last(TransportMessageIdStamp::class)?->getId());

        $envelopes = $this->getEnvelopes($this->confirmsTransport, 1);

        self::assertSame($messageId, $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getMessageId());

        self::assertSame([
            'x-deduplication-header' => $messageId,
            'x-test' => true,
        ], $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders());

        self::assertEquals([
            'content_type' => 'text/plain',
            'application_headers' => new AMQPTable([
                'x-test' => true,
                'x-deduplication-header' => $messageId,
            ]),
            'delivery_mode' => 2,
            'message_id' => $messageId,
        ], $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getAttributes());

        self::assertSame($messageId, $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testDelayedMessages(): void
    {
        $message = Envelope::wrap(new ConfirmMessage(1))->with(new DelayStamp(delay: 100));

        $this->bus->dispatch($message);

        $envelopes = $this->getEnvelopes($this->confirmsTransport, 1);

        self::assertCount(1, $envelopes);

        $envelope = $envelopes[0];

        $amqpEnvelope = $envelope->last(AmqpReceivedStamp::class)?->getAmqpEnvelope();

        self::assertNotNull($amqpEnvelope);

        self::assertSame('delays', $amqpEnvelope->getHeader('x-first-death-exchange'));
        self::assertSame('delay_test_confirms_exchange__100_delay', $amqpEnvelope->getHeader('x-first-death-queue'));
        self::assertSame('expired', $amqpEnvelope->getHeader('x-first-death-reason'));

        self::assertSame('delays', $amqpEnvelope->getHeader('x-last-death-exchange'));
        self::assertSame('delay_test_confirms_exchange__100_delay', $amqpEnvelope->getHeader('x-last-death-queue'));
        self::assertSame('expired', $amqpEnvelope->getHeader('x-last-death-reason'));
    }

    public function testBatchFlushRecoversAfterBrokerSocketIsDropped(): void
    {
        $this->drainTransport($this->confirmsTransport);

        $connection = $this->confirmsTransport->getConnection();
        $connection->channel();

        $batch = Batch::new($this->bus, 3);
        $batch->dispatch(new ConfirmMessage(101));
        $batch->dispatch(new ConfirmMessage(102));

        $this->dropUnderlyingAmqpSocket($connection);

        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $batch->flush();
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        $envelopes = $this->getEnvelopes($this->confirmsTransport, 2);

        self::assertCount(2, $envelopes);
        self::assertEquals(101, $envelopes[0]->getMessage()->count);
        self::assertEquals(102, $envelopes[1]->getMessage()->count);
    }

    public function testBatchFlushRecoversWhenSocketDropsOnAutoFlush(): void
    {
        $this->drainTransport($this->confirmsTransport);

        $connection = $this->confirmsTransport->getConnection();
        $connection->channel();

        $batch = Batch::new($this->bus, 2);
        $batch->dispatch(new ConfirmMessage(201));

        $this->dropUnderlyingAmqpSocket($connection);

        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            // Second dispatch fills the batch and auto-flushes against the dead socket.
            $batch->dispatch(new ConfirmMessage(202));
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        $envelopes = $this->getEnvelopes($this->confirmsTransport, 2);

        self::assertCount(2, $envelopes);
        self::assertEquals(201, $envelopes[0]->getMessage()->count);
        self::assertEquals(202, $envelopes[1]->getMessage()->count);
    }

    public function testTransactionsBatchFlushRecoversAfterBrokerSocketIsDropped(): void
    {
        $this->drainTransport($this->transactionsTransport);

        $connection = $this->transactionsTransport->getConnection();
        $connection->channel();

        $batch = Batch::new($this->bus, 3);
        $batch->dispatch(new TransactionMessage(301));
        $batch->dispatch(new TransactionMessage(302));

        $this->dropUnderlyingAmqpSocket($connection);

        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $batch->flush();
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        $envelopes = $this->getEnvelopes($this->transactionsTransport, 2);

        self::assertCount(2, $envelopes);
        self::assertEquals(301, $envelopes[0]->getMessage()->count);
        self::assertEquals(302, $envelopes[1]->getMessage()->count);
    }

    public function testConnectionPublishBatchFlushRecoversAfterBrokerSocketIsDropped(): void
    {
        $this->drainTransport($this->confirmsTransport);

        $connection = $this->confirmsTransport->getConnection();
        $connection->channel();

        $connection->publish(body: 'direct-batch-body-401', batchSize: 3);
        $connection->publish(body: 'direct-batch-body-402', batchSize: 3);

        self::assertSame(0, $connection->countMessagesInQueues());

        $this->dropUnderlyingAmqpSocket($connection);

        $previousWaitTime       = Retry::$defaultWaitTime;
        Retry::$defaultWaitTime = 0;

        try {
            $connection->flush();
        } finally {
            Retry::$defaultWaitTime = $previousWaitTime;
        }

        self::assertSame(2, $connection->countMessagesInQueues());

        // Raw bodies are not Messenger-encoded; purge so later tests stay isolated.
        $connection->channel()->queue_purge('test_confirms_queue');

        self::assertSame(0, $connection->countMessagesInQueues());
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $container = static::getContainer();

        $this->bus = $container->get(MessageBusInterface::class);

        $confirmsTransport = $container->get('messenger.transport.with_confirms');
        assert($confirmsTransport instanceof AmqpTransport);

        $this->confirmsTransport = $confirmsTransport;

        $transactionsTransport = $container->get('messenger.transport.with_transactions');
        assert($transactionsTransport instanceof AmqpTransport);

        $this->transactionsTransport = $transactionsTransport;

        $this->confirmsTransport->setup();
        $this->transactionsTransport->setup();
        $this->drainTransport($this->confirmsTransport);
        $this->drainTransport($this->transactionsTransport);
    }

    protected function tearDown(): void
    {
        $this->confirmsTransport->getConnection()->close();
        $this->transactionsTransport->getConnection()->close();
    }

    /** @param array<object> $messages */
    private function dispatchMessages(array $messages): void
    {
        $batch = Batch::new($this->bus, 2);

        foreach ($messages as $message) {
            $batch->dispatch($message);
        }

        $batch->flush();
    }

    /** @return array<Envelope> */
    private function getEnvelopes(AmqpTransport $transport, int $count, int $maxEmptyPolls = 100): array
    {
        if ($count === 0) {
            $this->drainTransport($transport);

            return [];
        }

        $collectedEnvelopes = [];
        $emptyPolls         = 0;

        while (count($collectedEnvelopes) < $count) {
            $receivedAny = false;

            /** @var Traversable<Envelope> $envelopes */
            $envelopes = $transport->get();

            foreach ($envelopes as $envelope) {
                $collectedEnvelopes[] = $envelope;
                $transport->ack($envelope);
                $receivedAny = true;
                $emptyPolls  = 0;

                if (count($collectedEnvelopes) === $count) {
                    return $collectedEnvelopes;
                }
            }

            if (! $receivedAny) {
                $emptyPolls++;

                if ($emptyPolls >= $maxEmptyPolls) {
                    self::fail(sprintf(
                        'Timed out waiting for %d envelope(s); received %d.',
                        $count,
                        count($collectedEnvelopes),
                    ));
                }
            }
        }

        return $collectedEnvelopes;
    }

    private function drainTransport(AmqpTransport $transport): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $drainedAny = false;

            /** @var Traversable<Envelope> $envelopes */
            $envelopes = $transport->get();

            foreach ($envelopes as $envelope) {
                $transport->ack($envelope);
                $drainedAny = true;
            }

            if (! $drainedAny) {
                return;
            }
        }
    }

    private function dropUnderlyingAmqpSocket(Connection $connection): void
    {
        $amqpConnectionProperty = new ReflectionProperty(Connection::class, 'connection');
        $amqpConnection         = $amqpConnectionProperty->getValue($connection);

        self::assertInstanceOf(AbstractConnection::class, $amqpConnection);

        // Close the TCP stream without going through a clean AMQP close so the next
        // publish_batch write fails with a broken pipe, matching the production failure.
        $amqpConnection->getIO()->close();
    }
}

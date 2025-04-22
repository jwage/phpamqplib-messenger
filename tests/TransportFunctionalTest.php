<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\BatchMessageBusInterface;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Message\ConfirmMessage;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Message\TransactionMessage;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Traversable;

use function assert;
use function count;

class TransportFunctionalTest extends KernelTestCase
{
    private BatchMessageBusInterface $bus;

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

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $container = static::getContainer();

        $this->bus = $container->get(BatchMessageBusInterface::class);

        $confirmsTransport = $container->get('messenger.transport.with_confirms');
        assert($confirmsTransport instanceof AmqpTransport);

        $this->confirmsTransport = $confirmsTransport;

        $transactionsTransport = $container->get('messenger.transport.with_transactions');
        assert($transactionsTransport instanceof AmqpTransport);

        $this->transactionsTransport = $transactionsTransport;
    }

    /** @param array<object> $messages */
    private function dispatchMessages(array $messages): void
    {
        $batch = $this->bus->getBatch(2);

        foreach ($messages as $message) {
            $batch->dispatch($message);
        }

        $batch->flush();
    }

    /** @return array<Envelope> */
    private function getEnvelopes(AMQPTransport $transport, int $count): array
    {
        $collectedEnvelopes = [];

        while (true) {
            /** @var Traversable<Envelope> $envelopes */
            $envelopes = $transport->get();

            foreach ($envelopes as $envelope) {
                $collectedEnvelopes[] = $envelope;

                $transport->ack($envelope);
            }

            if (count($collectedEnvelopes) === $count) {
                break;
            }
        }

        return $collectedEnvelopes;
    }
}

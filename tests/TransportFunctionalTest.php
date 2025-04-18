<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\BatchMessageBusInterface;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpTransport;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Traversable;

use function assert;
use function count;

class TransportFunctionalTest extends KernelTestCase
{
    private BatchMessageBusInterface $bus;

    private AmqpTransport $transport;

    public function testTransport(): void
    {
        $envelopes = $this->getEnvelopes(0);

        self::assertCount(0, $envelopes);

        $this->dispatchMessages();

        // test we can recover from a reconnect inbetween dispatching and consuming
        $this->transport->getConnection()->reconnect();

        $envelopes = $this->getEnvelopes(3);

        self::assertCount(3, $envelopes);

        self::assertEquals(1, $envelopes[0]->getMessage()->test);
        self::assertEquals(1, $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[0]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);

        self::assertEquals(2, $envelopes[1]->getMessage()->test);
        self::assertEquals(1, $envelopes[1]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[1]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);

        self::assertEquals(3, $envelopes[2]->getMessage()->test);
        self::assertEquals(1, $envelopes[2]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test1']);
        self::assertEquals(2, $envelopes[2]->last(AmqpReceivedStamp::class)?->getAmqpEnvelope()?->getHeaders()['test2']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $container = static::getContainer();

        $this->bus = $container->get(BatchMessageBusInterface::class);

        $transport = $container->get('messenger.transport.test_phpamqplib');
        assert($transport instanceof AmqpTransport);
        $this->purgeQueues($transport);

        $this->transport = $transport;
    }

    private function dispatchMessages(): void
    {
        $message1 = Envelope::wrap((object) ['test' => 1])->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));
        $message2 = Envelope::wrap((object) ['test' => 2])->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));
        $message3 = Envelope::wrap((object) ['test' => 3])->with(new AmqpStamp(attributes: ['headers' => ['test1' => 1, 'test2' => 2]]));

        $messages = [$message1, $message2, $message3];

        $batch = $this->bus->getBatch(2);

        foreach ($messages as $message) {
            $batch->dispatch($message);
        }

        $batch->flush();
    }

    /** @return array<Envelope> */
    private function getEnvelopes(int $count): array
    {
        $collectedEnvelopes = [];

        while (true) {
            /** @var Traversable<Envelope> $envelopes */
            $envelopes = $this->transport->get();

            foreach ($envelopes as $envelope) {
                $collectedEnvelopes[] = $envelope;

                $this->transport->ack($envelope);
            }

            if (count($collectedEnvelopes) === $count) {
                break;
            }
        }

        return $collectedEnvelopes;
    }

    private function purgeQueues(AmqpTransport $transport): void
    {
        $channel = $transport->getConnection()->channel();
        foreach ($transport->getConnection()->getQueueNames() as $queue) {
            try {
                $message = $channel->basic_get($queue);
            } catch (AMQPProtocolChannelException) {
                // channel probably doesn't exist
                continue;
            }

            while ($message !== null) {
                $message->ack();
            }
        }
    }
}

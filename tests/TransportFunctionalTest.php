<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use Jwage\PhpAmqpLibMessengerBundle\BatchMessageBusInterface;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Traversable;

use function assert;
use function count;
use function iterator_to_array;

class TransportFunctionalTest extends KernelTestCase
{
    private BatchMessageBusInterface $bus;

    private AMQPTransport $transport;

    public function testTransport(): void
    {
        $message1 = (object) ['test' => 1];
        $message2 = (object) ['test' => 2];
        $message3 = (object) ['test' => 3];

        $messages = [$message1, $message2, $message3];

        $this->bus->dispatchBatches($messages, 2);

        $envelopes = $this->getEnvelopes(3);

        self::assertCount(3, $envelopes);

        self::assertEquals(1, $envelopes[0]->getMessage()->test);
        self::assertEquals(2, $envelopes[1]->getMessage()->test);
        self::assertEquals(3, $envelopes[2]->getMessage()->test);
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $container = static::getContainer();

        $this->bus = $container->get(BatchMessageBusInterface::class);

        $transport = $container->get('messenger.transport.test_phpamqplib');
        assert($transport instanceof AMQPTransport);

        $this->transport = $transport;
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

    private function getEnvelope(): Envelope|null
    {
        /** @var Traversable<Envelope> $iterable */
        $iterable = $this->transport->get();

        $envelopes = iterator_to_array($iterable);

        $envelope = $envelopes[0] ?? null;

        if ($envelope !== null) {
            $this->transport->ack($envelope);
        }

        return $envelope;
    }
}

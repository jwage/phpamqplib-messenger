<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Generator;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConsumer;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceiver;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Traversable;

use function array_shift;
use function iterator_to_array;
use function serialize;

class AmqpReceiverTest extends TestCase
{
    private LoggerInterface $logger;

    private RetryFactory $retryFactory;

    private AmqpConnectionFactory $amqpConnectionFactory;

    private ConnectionConfig $connectionConfig;

    public function testGet(): void
    {
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage  = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpEnvelope = new AmqpEnvelope($amqpMessage);

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name']);

        $connection->expects(self::once())
            ->method('consume')
            ->willReturn([$amqpEnvelope]);

        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects(self::once())
            ->method('decode')
            ->with(['body' => 'O:8:"stdClass":0:{}', 'headers' => []])
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);
        $iterable = $receiver->get();

        $envelopes = [];

        foreach ($iterable as $envelope) {
            $envelopes[] = $envelope;
        }

        self::assertCount(1, $envelopes);

        $envelope1 = $envelopes[0];

        $transportMessageIdStamp1 = $envelope1->last(TransportMessageIdStamp::class);

        self::assertInstanceOf(TransportMessageIdStamp::class, $transportMessageIdStamp1);
        self::assertSame('1', $transportMessageIdStamp1->getId());

        $amqpReceivedStamp1 = $envelope1->last(AmqpReceivedStamp::class);

        self::assertInstanceOf(AmqpReceivedStamp::class, $amqpReceivedStamp1);
        self::assertSame($amqpEnvelope, $amqpReceivedStamp1->getAMQPEnvelope());
        self::assertSame('queue_name', $amqpReceivedStamp1->getQueueName());
    }

    public function testGetWithoutFetchSizeYieldsAllAvailable(): void
    {
        // Symfony < 8.1 calls get() with no argument. The receiver must NOT cap the
        // result at 1; it must drain whatever the underlying source produces, matching
        // the pre-Symfony-8.1 behavior.
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3 = new AMQPMessage(serialize($message), ['message_id' => '3']);

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name']);

        $connection->expects(self::once())
            ->method('consume')
            ->willReturn([
                new AmqpEnvelope($amqpMessage1),
                new AmqpEnvelope($amqpMessage2),
                new AmqpEnvelope($amqpMessage3),
            ]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(3))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(3, $this->envelopesToList($receiver->get()));
    }

    public function testGetFromQueuesWithoutFetchSizeYieldsAllAvailable(): void
    {
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3 = new AMQPMessage(serialize($message), ['message_id' => '3']);

        $connection = $this->getTestConnection();

        $connection->expects(self::once())
            ->method('consume')
            ->with('queue_name', null)
            ->willReturn([
                new AmqpEnvelope($amqpMessage1),
                new AmqpEnvelope($amqpMessage2),
                new AmqpEnvelope($amqpMessage3),
            ]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(3))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(3, $this->envelopesToList($receiver->getFromQueues(['queue_name'])));
    }

    public function testGetUsesConfiguredFetchSizeWhenArgumentOmitted(): void
    {
        $this->connectionConfig = new ConnectionConfig(fetchSize: 2);

        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3 = new AMQPMessage(serialize($message), ['message_id' => '3']);

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name']);

        $connection->expects(self::once())
            ->method('consume')
            ->with('queue_name', 2)
            ->willReturn([
                new AmqpEnvelope($amqpMessage1),
                new AmqpEnvelope($amqpMessage2),
                new AmqpEnvelope($amqpMessage3),
            ]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(2))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(2, $this->envelopesToList($receiver->get()));
    }

    public function testGetFromQueuesUsesConfiguredFetchSizeWhenArgumentOmitted(): void
    {
        $this->connectionConfig = new ConnectionConfig(fetchSize: 2);

        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3 = new AMQPMessage(serialize($message), ['message_id' => '3']);

        $connection = $this->getTestConnection();

        $connection->expects(self::once())
            ->method('consume')
            ->with('queue_name', 2)
            ->willReturn([
                new AmqpEnvelope($amqpMessage1),
                new AmqpEnvelope($amqpMessage2),
                new AmqpEnvelope($amqpMessage3),
            ]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(2))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(2, $this->envelopesToList($receiver->getFromQueues(['queue_name'])));
    }

    public function testExplicitFetchSizeOverridesConfiguredFetchSize(): void
    {
        $this->connectionConfig = new ConnectionConfig(fetchSize: 2);

        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3 = new AMQPMessage(serialize($message), ['message_id' => '3']);

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name']);

        $connection->expects(self::once())
            ->method('consume')
            ->with('queue_name', 3)
            ->willReturn([
                new AmqpEnvelope($amqpMessage1),
                new AmqpEnvelope($amqpMessage2),
                new AmqpEnvelope($amqpMessage3),
            ]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(3))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(3, $this->envelopesToList($receiver->get(3)));
    }

    public function testGetAcceptsFetchSize(): void
    {
        $message       = new stdClass();
        $envelope      = new Envelope($message);
        $amqpMessage1  = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2  = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3  = new AMQPMessage(serialize($message), ['message_id' => '3']);
        $amqpEnvelopes = [
            new AmqpEnvelope($amqpMessage1),
            new AmqpEnvelope($amqpMessage2),
            new AmqpEnvelope($amqpMessage3),
        ];

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name']);

        $connection->expects(self::once())
            ->method('consume')
            ->willReturn($amqpEnvelopes);

        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects(self::exactly(2))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(2, $this->envelopesToList($receiver->get(2)));
    }

    public function testGetFromQueuesAcceptsFetchSize(): void
    {
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3 = new AMQPMessage(serialize($message), ['message_id' => '3']);

        $connection = $this->getTestConnection();

        $connection->expects(self::once())
            ->method('consume')
            ->with('queue_name', 2)
            ->willReturn([
                new AmqpEnvelope($amqpMessage1),
                new AmqpEnvelope($amqpMessage2),
                new AmqpEnvelope($amqpMessage3),
            ]);

        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects(self::exactly(2))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(2, $this->envelopesToList($receiver->getFromQueues(['queue_name'], 2)));
    }

    public function testGetReturnsLeftoverEnvelopesOnNextCall(): void
    {
        // Models the production wiring: Connection::consume() returns a fresh generator
        // on every call but drains from a shared buffer on the underlying AmqpConsumer.
        // The receiver must honor fetchSize *and* leave the surplus on the buffer for
        // the next get() call, otherwise messages get silently dropped.
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3 = new AMQPMessage(serialize($message), ['message_id' => '3']);
        $sharedBuffer = [
            new AmqpEnvelope($amqpMessage1),
            new AmqpEnvelope($amqpMessage2),
            new AmqpEnvelope($amqpMessage3),
        ];

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name']);

        $connection->expects(self::exactly(2))
            ->method('consume')
            ->with('queue_name', 2)
            ->willReturnCallback(static function () use (&$sharedBuffer): Generator {
                while (($amqpEnvelope = array_shift($sharedBuffer)) !== null) {
                    yield $amqpEnvelope;
                }
            });

        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects(self::exactly(3))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(2, $this->envelopesToList($receiver->get(2)));
        self::assertCount(1, $this->envelopesToList($receiver->get(2)));
        self::assertCount(0, $sharedBuffer);
    }

    public function testGetWithFetchSizeUsingRealConsumer(): void
    {
        // Wires a real AmqpConsumer (with a mocked AMQPChannel) through the receiver so
        // the shared-buffer plumbing — including the array_shift drain that survives
        // generator destruction — is exercised at the receiver layer too, not just via
        // a stubbed Connection::consume().
        $message = new stdClass();

        $connectionConfig = new ConnectionConfig(
            queues: [
                'queue_name' => new QueueConfig(
                    name: 'queue_name',
                    prefetchCount: 5,
                    waitTimeout: 2,
                ),
            ],
        );

        $channel = $this->createMock(AMQPChannel::class);
        $channel->expects(self::once())
            ->method('basic_qos')
            ->with(
                prefetch_size: 0,
                prefetch_count: 5,
                a_global: false,
            );
        $channel->expects(self::once())
            ->method('basic_consume')
            ->willReturn('consumer_tag');
        $channel->expects(self::never())
            ->method('wait');

        $connection = self::getStubBuilder(Connection::class)
            ->onlyMethods(['consumerChannel', 'getQueueNames', 'consume', 'close'])
            ->setConstructorArgs([
                $this->retryFactory,
                $this->amqpConnectionFactory,
                $connectionConfig,
                $this->logger,
            ])
            ->getStub();
        $connection->method('consumerChannel')
            ->willReturn($channel);
        $connection->method('getQueueNames')
            ->willReturn(['queue_name']);

        $consumer = new AmqpConsumer($connection, $connectionConfig, $this->logger);

        // Mirror Connection::consume() in production: delegate to the cached AmqpConsumer
        // so the shared buffer carries over between get() calls.
        $connection->method('consume')
            ->willReturnCallback(static fn (string $queueName, int|null $fetchSize = null) => $consumer->consume($queueName, $fetchSize));

        $consumer->callback(new AMQPMessage(serialize($message), ['message_id' => '1']));
        $consumer->callback(new AMQPMessage(serialize($message), ['message_id' => '2']));
        $consumer->callback(new AMQPMessage(serialize($message), ['message_id' => '3']));

        $envelope   = new Envelope($message);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(3))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(2, $this->envelopesToList($receiver->get(2)));
        self::assertCount(1, $this->envelopesToList($receiver->get(2)));
    }

    public function testGetWithFetchSizeDoesNotConsumeFurtherQueuesOnceLimitIsReached(): void
    {
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name', 'queue_name2']);

        $connection->expects(self::once())
            ->method('consume')
            ->with('queue_name', 2)
            ->willReturn([
                new AmqpEnvelope($amqpMessage1),
                new AmqpEnvelope($amqpMessage2),
            ]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(2))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(2, $this->envelopesToList($receiver->get(2)));
    }

    public function testGetWithFetchSizeConsumesAdditionalQueuesUntilLimitIsReached(): void
    {
        $message      = new stdClass();
        $envelope     = new Envelope($message);
        $amqpMessage1 = new AMQPMessage(serialize($message), ['message_id' => '1']);
        $amqpMessage2 = new AMQPMessage(serialize($message), ['message_id' => '2']);
        $amqpMessage3 = new AMQPMessage(serialize($message), ['message_id' => '3']);

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name', 'queue_name2']);

        $connection->expects(self::exactly(2))
            ->method('consume')
            ->willReturnCallback(
                static function (string $queueName, int|null $fetchSize = null) use ($amqpMessage1, $amqpMessage2, $amqpMessage3): array {
                    self::assertSame(3, $fetchSize);

                    return match ($queueName) {
                        'queue_name' => [
                            new AmqpEnvelope($amqpMessage1),
                            new AmqpEnvelope($amqpMessage2),
                        ],
                        'queue_name2' => [
                            new AmqpEnvelope($amqpMessage3),
                        ],
                        default => [],
                    };
                },
            );

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(3))
            ->method('decode')
            ->willReturn($envelope);

        $receiver = new AmqpReceiver($connection, $serializer);

        self::assertCount(3, $this->envelopesToList($receiver->get(3)));
    }

    public function testGetConsumesEachConfiguredQueue(): void
    {
        $message1      = new stdClass();
        $message2      = new stdClass();
        $envelope1     = new Envelope($message1);
        $envelope2     = new Envelope($message2);
        $amqpEnvelope1 = new AmqpEnvelope(new AMQPMessage(serialize($message1), ['message_id' => '1']));
        $amqpEnvelope2 = new AmqpEnvelope(new AMQPMessage(serialize($message2), ['message_id' => '2']));

        $connection = $this->getTestConnection();
        $connection->method('getQueueNames')
            ->willReturn(['queue_name', 'queue_name2']);

        $connection->expects(self::exactly(2))
            ->method('consume')
            ->willReturnCallback(
                static function (string $queueName) use ($amqpEnvelope1, $amqpEnvelope2): array {
                    return match ($queueName) {
                        'queue_name' => [$amqpEnvelope1],
                        'queue_name2' => [$amqpEnvelope2],
                        default => [],
                    };
                },
            );

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(2))
            ->method('decode')
            ->willReturnOnConsecutiveCalls($envelope1, $envelope2);

        $receiver = new AmqpReceiver($connection, $serializer);

        $envelopes = [];

        foreach ($receiver->get() as $envelope) {
            $envelopes[] = $envelope;
        }

        self::assertCount(2, $envelopes);

        $amqpReceivedStamp1 = $envelopes[0]->last(AmqpReceivedStamp::class);
        $amqpReceivedStamp2 = $envelopes[1]->last(AmqpReceivedStamp::class);

        self::assertInstanceOf(AmqpReceivedStamp::class, $amqpReceivedStamp1);
        self::assertInstanceOf(AmqpReceivedStamp::class, $amqpReceivedStamp2);
        self::assertSame($amqpEnvelope1, $amqpReceivedStamp1->getAMQPEnvelope());
        self::assertSame('queue_name', $amqpReceivedStamp1->getQueueName());
        self::assertSame($amqpEnvelope2, $amqpReceivedStamp2->getAMQPEnvelope());
        self::assertSame('queue_name2', $amqpReceivedStamp2->getQueueName());
    }

    public function testGetNacksWhenDecodeThrows(): void
    {
        $amqpEnvelope = $this->createMock(AmqpEnvelope::class);
        $amqpEnvelope->method('getBody')->willReturn('bad');
        $amqpEnvelope->method('getHeaders')->willReturn([]);
        $amqpEnvelope->expects(self::once())->method('nack');

        $connection = $this->getTestConnection();
        $connection->expects(self::once())
            ->method('getQueueNames')
            ->willReturn(['queue_name']);
        $connection->expects(self::once())
            ->method('consume')
            ->willReturn([$amqpEnvelope]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->willThrowException(new MessageDecodingFailedException('bad'));

        $receiver = new AmqpReceiver($connection, $serializer);

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('bad');

        /** @var Traversable<mixed, Envelope> $envelopes */
        $envelopes = $receiver->get();
        iterator_to_array($envelopes);
    }

    public function testGetNacksWhenDecodeThrowsANonDecodingException(): void
    {
        $amqpEnvelope = $this->createMock(AmqpEnvelope::class);
        $amqpEnvelope->method('getBody')->willReturn('bad');
        $amqpEnvelope->method('getHeaders')->willReturn([]);
        $amqpEnvelope->expects(self::once())->method('nack');

        $connection = $this->getTestConnection();
        $connection->expects(self::once())
            ->method('getQueueNames')
            ->willReturn(['queue_name']);
        $connection->expects(self::once())
            ->method('consume')
            ->willReturn([$amqpEnvelope]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->willThrowException(new RuntimeException('refusing to decode it'));

        $receiver = new AmqpReceiver($connection, $serializer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to decode it');

        /** @var Traversable<mixed, Envelope> $envelopes */
        $envelopes = $receiver->get();
        iterator_to_array($envelopes);
    }

    public function testGetNacksWhenDecodeReturnsAFailureEnvelope(): void
    {
        $amqpEnvelope = $this->createMock(AmqpEnvelope::class);
        $amqpEnvelope->method('getBody')->willReturn('bad');
        $amqpEnvelope->method('getHeaders')->willReturn([]);
        $amqpEnvelope->expects(self::once())->method('nack');

        $connection = $this->getTestConnection();
        $connection->expects(self::once())
            ->method('getQueueNames')
            ->willReturn(['queue_name']);
        $connection->expects(self::once())
            ->method('consume')
            ->willReturn([$amqpEnvelope]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->willReturn(new Envelope(new MessageDecodingFailedException('bad')));

        $receiver = new AmqpReceiver($connection, $serializer);

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('bad');

        /** @var Traversable<mixed, Envelope> $envelopes */
        $envelopes = $receiver->get();
        iterator_to_array($envelopes);
    }

    public function testAck(): void
    {
        $amqpEnvelope = $this->createMock(AmqpEnvelope::class);

        $amqpEnvelope->expects(self::once())
            ->method('ack');

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'queue_name');

        $envelope = new Envelope(new stdClass(), [$stamp]);

        $receiver = new AmqpReceiver(
            $this->getTestConnectionStub(),
            $this->createStub(SerializerInterface::class),
        );

        $receiver->ack($envelope);
    }

    public function testReject(): void
    {
        $amqpEnvelope = $this->createMock(AmqpEnvelope::class);

        $amqpEnvelope->expects(self::once())
            ->method('nack');

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'queue_name');

        $envelope = new Envelope(new stdClass(), [$stamp]);

        $receiver = new AmqpReceiver(
            $this->getTestConnectionStub(),
            $this->createStub(SerializerInterface::class),
        );

        $receiver->reject($envelope);
    }

    public function testGetMessageCount(): void
    {
        $connection = $this->getTestConnection();

        $connection->expects(self::once())
            ->method('countMessagesInQueues')
            ->willReturn(10);

        $receiver = new AmqpReceiver(
            $connection,
            $this->createStub(SerializerInterface::class),
        );

        self::assertSame(10, $receiver->getMessageCount());
    }

    public function testGetDoesNotWaitForDeliveries(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->onlyMethods([
                'getQueueNames',
                'consume',
                'countMessagesInQueues',
                'waitForDeliveries',
            ])
            ->setConstructorArgs([
                $this->retryFactory,
                $this->amqpConnectionFactory,
                $this->connectionConfig,
                $this->logger,
            ])
            ->getMock();

        $connection->expects(self::never())
            ->method('waitForDeliveries');
        $connection->method('getQueueNames')
            ->willReturn(['queue_name', 'queue_name2']);
        $connection->expects(self::exactly(2))
            ->method('consume')
            ->willReturn([]);

        $receiver = new AmqpReceiver(
            $connection,
            $this->createStub(SerializerInterface::class),
        );

        self::assertSame([], $this->envelopesToList($receiver->get()));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createStub(LoggerInterface::class);

        $this->retryFactory = new RetryFactory($this->logger);

        $this->amqpConnectionFactory = $this->createStub(AmqpConnectionFactory::class);

        $this->connectionConfig = new ConnectionConfig();
    }

    /**
     * @param iterable<Envelope> $envelopes
     *
     * @return list<Envelope>
     */
    private function envelopesToList(iterable $envelopes): array
    {
        /** @var Traversable<mixed, Envelope> $traversable */
        $traversable = $envelopes;

        return iterator_to_array($traversable, false);
    }

    private function getTestConnectionStub(): Connection&Stub
    {
        return self::getStubBuilder(Connection::class)
            ->onlyMethods(['getQueueNames', 'consume', 'countMessagesInQueues'])
            ->setConstructorArgs([
                $this->retryFactory,
                $this->amqpConnectionFactory,
                $this->connectionConfig,
                $this->logger,
            ])
            ->getStub();
    }

    private function getTestConnection(): Connection&MockObject
    {
        return $this->getMockBuilder(Connection::class)
            ->onlyMethods(['getQueueNames', 'consume', 'countMessagesInQueues'])
            ->setConstructorArgs([
                $this->retryFactory,
                $this->amqpConnectionFactory,
                $this->connectionConfig,
                $this->logger,
            ])
            ->getMock();
    }
}

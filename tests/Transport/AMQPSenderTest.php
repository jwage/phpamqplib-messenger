<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPBatchStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPSender;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AMQPStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AMQPSenderTest extends TestCase
{
    private RetryFactory $retryFactory;

    /** @var Connection&MockObject */
    private Connection $connection;

    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    private AMQPSender $sender;

    public function testSend(): void
    {
        $amqpEnvelope = new AMQPEnvelope(new AMQPMessage('test'));

        $amqpBatchStamp = new AMQPBatchStamp(1);

        $delayStamp = new DelayStamp(1000);

        $amqpStamp = new AMQPStamp(null, [
            'message_id' => '123',
            'headers' => [],
            'content_type' => null,
            'content_encoding' => null,
            'priority' => null,
            'correlation_id' => null,
            'reply_to' => null,
            'expiration' => null,
            'message_type' => null,
            'user_id' => null,
            'delivery_mode' => null,
            'timestamp' => null,
            'app_id' => null,
            'type' => null,
        ]);

        $amqpReceivedStamp = new AMQPReceivedStamp($amqpEnvelope, 'queue_name');

        $message  = new stdClass();
        $envelope = new Envelope($message, [
            $amqpBatchStamp,
            $delayStamp,
            $amqpStamp,
            $amqpReceivedStamp,
        ]);

        $this->serializer->expects(self::once())
            ->method('encode')
            ->with($envelope)
            ->willReturn(['body' => 'body', 'headers' => []]);

        $this->connection->expects(self::once())
            ->method('publish')
            ->with(
                body: 'body',
                delayInMs: 1000,
                batchSize: 1,
                amqpStamp: $amqpStamp,
            );

        $newEnvelope = $this->sender->send($envelope);

        self::assertNotSame($envelope, $newEnvelope);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->retryFactory = new RetryFactory();

        $this->connection = $this->createMock(Connection::class);

        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->sender = new AMQPSender(
            $this->retryFactory,
            $this->connection,
            $this->serializer,
        );
    }
}

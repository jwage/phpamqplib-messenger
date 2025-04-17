<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Stamp\Deferrable;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpReceivedStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpSender;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpSenderTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $connection;

    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    private AmqpSender $sender;

    public function testSend(): void
    {
        $amqpEnvelope = new AmqpEnvelope(new AMQPMessage('test', [
            'application_headers' => new AMQPTable(['header1' => 'value1', 'header2' => 'value2']),
        ]));

        $deferrableStamp = new Deferrable(1);

        $delayStamp = new DelayStamp(1000);

        $amqpStamp = new AmqpStamp(null, [
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

        $amqpReceivedStamp = new AmqpReceivedStamp($amqpEnvelope, 'queue_name');

        $message  = new stdClass();
        $envelope = new Envelope($message, [
            $deferrableStamp,
            $delayStamp,
            $amqpStamp,
            $amqpReceivedStamp,
        ]);

        $this->serializer->expects(self::once())
            ->method('encode')
            ->with($envelope)
            ->willReturn(['body' => 'body', 'headers' => ['header1' => 'value1', 'header2' => 'value2']]);

        $this->connection->expects(self::once())
            ->method('publish')
            ->with(
                body: 'body',
                headers: ['header1' => 'value1', 'header2' => 'value2'],
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

        $this->connection = $this->createMock(Connection::class);

        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->sender = new AmqpSender(
            $this->connection,
            $this->serializer,
        );
    }
}

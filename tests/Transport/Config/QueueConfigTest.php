<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use PHPUnit\Framework\TestCase;

class QueueConfigTest extends TestCase
{
    public function testDefaultConstruct(): void
    {
        self::assertDefaultQueueConfig(new QueueConfig());
    }

    public function testFromArrayWithEmptyArray(): void
    {
        self::assertDefaultQueueConfig(QueueConfig::fromArray([]));
    }

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidQueueConfig(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid queue option(s) "invalid" passed to the AMQP Messenger transport.');

        QueueConfig::fromArray(['invalid' => true]);
    }

    public function testFromArray(): void
    {
        $queueConfig = QueueConfig::fromArray([
            'prefetch_count' => 10,
            'wait_timeout' => 2.0,
            'passive' => true,
            'durable' => false,
            'exclusive' => true,
            'auto_delete' => false,
            'binding_keys' => ['key1', 'key2'],
            'binding_arguments' => ['arg1' => 'value1', 'arg2' => 'value2'],
            'arguments' => ['arg3' => 'value3', 'arg4' => 'value4'],
        ]);

        self::assertSame(10, $queueConfig->prefetchCount);
        self::assertSame(2.0, $queueConfig->waitTimeout);
        self::assertTrue($queueConfig->passive);
        self::assertFalse($queueConfig->durable);
        self::assertTrue($queueConfig->exclusive);
        self::assertFalse($queueConfig->autoDelete);
        self::assertSame(['key1', 'key2'], $queueConfig->bindingKeys);
        self::assertSame(['arg1' => 'value1', 'arg2' => 'value2'], $queueConfig->bindingArguments);
        self::assertSame(['arg3' => 'value3', 'arg4' => 'value4'], $queueConfig->arguments);
    }

    private static function assertDefaultQueueConfig(QueueConfig $queueConfig): void
    {
        self::assertSame(ConnectionConfig::DEFAULT_PREFETCH_COUNT, $queueConfig->prefetchCount);

        self::assertSame(ConnectionConfig::DEFAULT_WAIT_TIMEOUT, $queueConfig->waitTimeout);
        self::assertFalse($queueConfig->passive);
        self::assertTrue($queueConfig->durable);
        self::assertFalse($queueConfig->exclusive);
        self::assertFalse($queueConfig->autoDelete);
        self::assertSame([], $queueConfig->bindingKeys);
        self::assertSame([], $queueConfig->bindingArguments);
        self::assertSame([], $queueConfig->arguments);
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\BindingConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\QueueConfig;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use stdClass;

class QueueConfigTest extends TestCase
{
    public function testEmptyConstruct(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Queue name is required');

        new QueueConfig();
    }

    public function testDefaultConstruct(): void
    {
        self::assertDefaultQueueConfig(new QueueConfig(name: 'queue_name'));
    }

    public function testFromArrayWithEmptyArray(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Queue name is required');

        QueueConfig::fromArray([]);
    }

    public function testBindingsGetIndexedByRoutingKey(): void
    {
        $queueConfig = new QueueConfig(
            name: 'queue_name',
            bindings: [
                new BindingConfig(routingKey: 'routing_key1'),
                new BindingConfig(routingKey: 'routing_key2'),
            ],
        );

        self::assertSame('queue_name', $queueConfig->name);
        self::assertEquals([
            'routing_key1' => new BindingConfig(routingKey: 'routing_key1'),
            'routing_key2' => new BindingConfig(routingKey: 'routing_key2'),
        ], $queueConfig->bindings);
    }

    public function testBindingsKeyMustMatchRoutingKey(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Binding routing key "routing_key1" does not match array key "routing_key2"');

        new QueueConfig(
            name: 'queue_name',
            bindings: ['routing_key2' => new BindingConfig(routingKey: 'routing_key1')],
        );
    }

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidOptions(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid queue option(s) "invalid" passed to the AMQP Messenger transport - known options:');

        QueueConfig::fromArray(['invalid' => true]);
    }

    public function testFromArray(): void
    {
        $queueConfig = QueueConfig::fromArray([
            'name' => 'queue_name',
            'prefetch_count' => 10,
            'wait_timeout' => 2.0,
            'passive' => true,
            'durable' => false,
            'exclusive' => true,
            'auto_delete' => false,
            'bindings' => ['routing_key' => ['arguments' => ['arg1' => 'value1', 'arg2' => 'value2']]],
            'arguments' => ['arg3' => 'value3', 'arg4' => 'value4'],
        ]);

        self::assertSame('queue_name', $queueConfig->name);
        self::assertSame(10, $queueConfig->prefetchCount);
        self::assertSame(2.0, $queueConfig->waitTimeout);
        self::assertTrue($queueConfig->passive);
        self::assertFalse($queueConfig->durable);
        self::assertTrue($queueConfig->exclusive);
        self::assertFalse($queueConfig->autoDelete);
        self::assertEquals([
            'routing_key' => BindingConfig::fromArray([
                'routing_key' => 'routing_key',
                'arguments' => ['arg1' => 'value1', 'arg2' => 'value2'],
            ]),
        ], $queueConfig->bindings);
        self::assertSame(['arg3' => 'value3', 'arg4' => 'value4'], $queueConfig->arguments);
    }

    public function testFromArrayUsesBindingArrayKeyAsRoutingKey(): void
    {
        $queueConfig = QueueConfig::fromArray([
            'name' => 'queue_name',
            'bindings' => ['routing_key' => null],
        ]);

        self::assertSame('queue_name', $queueConfig->name);
        self::assertSame('routing_key', $queueConfig->bindings['routing_key']->routingKey);
        self::assertSame([], $queueConfig->bindings['routing_key']->arguments);
    }

    public function testFromArrayWithEmptyBinding(): void
    {
        $queueConfig = QueueConfig::fromArray([
            'name' => 'queue_name',
            'bindings' => ['routing_key' => []],
        ]);

        self::assertSame('queue_name', $queueConfig->name);
        self::assertSame('routing_key', $queueConfig->bindings['routing_key']->routingKey);
        self::assertSame([], $queueConfig->bindings['routing_key']->arguments);
    }

    public function testFromArrayWithInvalidTypes(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "object" for key "prefetch_count" (expected integer)');

        QueueConfig::fromArray(['prefetch_count' => new stdClass()]);
    }

    #[TestWith([0])]
    #[TestWith([0.0])]
    public function testWaitTimeoutCannotBeZero(int|float $waitTimeout): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Queue "queue_name" wait timeout cannot be zero. This will cause the consumer to wait forever and block the messenger worker loop.');

        new QueueConfig(name: 'queue_name', waitTimeout: $waitTimeout);
    }

    public function testBindingKeysSymfonyAmqpMessengerCompatibility(): void
    {
        $queueConfig = QueueConfig::fromArray([
            'name' => 'queue_name',
            'binding_keys' => ['routing_key1', 'routing_key2'],
        ]);

        self::assertEquals([
            'routing_key1' => new BindingConfig(routingKey: 'routing_key1'),
            'routing_key2' => new BindingConfig(routingKey: 'routing_key2'),
        ], $queueConfig->bindings);
    }

    public function testCannotSetBothBindingsAndBindingKeys(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid queue config: "bindings" and "binding_keys" cannot both be set. binding_keys is only available for compatibility with symfony/amqp-messenger. It is recommended to use "bindings" instead.');

        QueueConfig::fromArray(['binding_keys' => [], 'bindings' => []]);
    }

    private static function assertDefaultQueueConfig(QueueConfig $queueConfig): void
    {
        self::assertSame('queue_name', $queueConfig->name);
        self::assertSame(ConnectionConfig::DEFAULT_PREFETCH_COUNT, $queueConfig->prefetchCount);
        self::assertSame(ConnectionConfig::DEFAULT_WAIT_TIMEOUT, $queueConfig->waitTimeout);
        self::assertFalse($queueConfig->passive);
        self::assertTrue($queueConfig->durable);
        self::assertFalse($queueConfig->exclusive);
        self::assertFalse($queueConfig->autoDelete);
        self::assertSame([], $queueConfig->bindings);
        self::assertSame([], $queueConfig->arguments);
    }
}

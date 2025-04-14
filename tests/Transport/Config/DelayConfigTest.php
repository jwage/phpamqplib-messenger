<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\DelayConfig;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PHPUnit\Framework\TestCase;

class DelayConfigTest extends TestCase
{
    public function testDefaultConstruct(): void
    {
        $delayConfig = new DelayConfig();

        self::assertSame('delays', $delayConfig->exchange->name);
        self::assertSame(AMQPExchangeType::DIRECT, $delayConfig->exchange->type);
        self::assertSame('delay_%exchange_name%_%routing_key%_%delay%', $delayConfig->queueNamePattern);
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $delayConfig = DelayConfig::fromArray([]);

        self::assertSame('delays', $delayConfig->exchange->name);
        self::assertSame(AMQPExchangeType::DIRECT, $delayConfig->exchange->type);
        self::assertSame('delay_%exchange_name%_%routing_key%_%delay%', $delayConfig->queueNamePattern);
    }

    public function testFromArray(): void
    {
        $delayConfig = DelayConfig::fromArray([
            'exchange' => [
                'name' => 'exchange_name',
                'default_publish_routing_key' => 'routing_key',
                'type' => 'direct',
                'passive' => true,
                'durable' => true,
                'auto_delete' => true,
                'arguments' => ['arg1' => 'val1', 'arg2' => 'val2'],
            ],
            'queue_name_pattern' => 'delay_%exchange_name%_%routing_key%_%delay%',
            'arguments' => ['arg3' => 'val3', 'arg4' => 'val4'],
        ]);

        self::assertSame('exchange_name', $delayConfig->exchange->name);
        self::assertSame('routing_key', $delayConfig->exchange->defaultPublishRoutingKey);
        self::assertSame(AMQPExchangeType::DIRECT, $delayConfig->exchange->type);
        self::assertTrue($delayConfig->exchange->passive);
        self::assertTrue($delayConfig->exchange->durable);
        self::assertTrue($delayConfig->exchange->autoDelete);
        self::assertSame(['arg1' => 'val1', 'arg2' => 'val2'], $delayConfig->exchange->arguments);
        self::assertSame('delay_%exchange_name%_%routing_key%_%delay%', $delayConfig->queueNamePattern);
        self::assertSame(['arg3' => 'val3', 'arg4' => 'val4'], $delayConfig->arguments);
    }

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidOptions(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid delay option(s) "invalid" passed to the AMQP Messenger transport.');

        DelayConfig::fromArray(['invalid' => true]);
    }

    public function testGetQueueName(): void
    {
        $delayConfig = new DelayConfig();

        self::assertSame('delay_delays_routing_key_1000_retry', $delayConfig->getQueueName(
            delay: 1000,
            routingKey: 'routing_key',
            isRetryAttempt: true,
        ));

        self::assertSame('delay_delays_routing_key_1000_delay', $delayConfig->getQueueName(
            delay: 1000,
            routingKey: 'routing_key',
            isRetryAttempt: false,
        ));

        self::assertSame('delay_delays__1000_delay', $delayConfig->getQueueName(
            delay: 1000,
            routingKey: null,
            isRetryAttempt: false,
        ));
    }
}

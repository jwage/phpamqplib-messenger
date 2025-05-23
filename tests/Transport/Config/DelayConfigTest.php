<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\DelayConfig;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PHPUnit\Framework\TestCase;
use stdClass;

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
        self::expectExceptionMessage('Invalid delay option(s) "invalid" passed to the AMQP Messenger transport - known options:');

        DelayConfig::fromArray(['invalid' => true]);
    }

    public function testFromArrayWithInvalidTypes(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "object" for key "enabled" (expected boolean)');

        DelayConfig::fromArray(['enabled' => new stdClass()]);
    }

    // New tests for curly brace placeholder functionality

    public function testFromArrayWithCurlyBracePlaceholders(): void
    {
        $config = [
            'queue_name_pattern' => 'message-bus.delay-queue.{delay}',
        ];

        $delayConfig = DelayConfig::fromArray($config);

        // The curly braces should be converted to % placeholders for runtime use
        self::assertSame('message-bus.delay-queue.%delay%', $delayConfig->queueNamePattern);
    }

    public function testFromArrayWithMultipleCurlyBracePlaceholders(): void
    {
        $config = [
            'queue_name_pattern' => 'delay-{exchange_name}-{routing_key}-{delay}',
        ];

        $delayConfig = DelayConfig::fromArray($config);

        self::assertSame('delay-%exchange_name%-%routing_key%-%delay%', $delayConfig->queueNamePattern);
    }

    public function testFromArrayWithMixedPlaceholders(): void
    {
        $config = [
            'queue_name_pattern' => '{exchange_name}.delay-queue.%app.prefix%.{delay}',
        ];

        $delayConfig = DelayConfig::fromArray($config);

        // Only curly braces should be converted, % placeholders should remain
        self::assertSame('%exchange_name%.delay-queue.%app.prefix%.%delay%', $delayConfig->queueNamePattern);
    }

    public function testFromArrayWithPercentPlaceholders(): void
    {
        $config = [
            'queue_name_pattern' => 'message-bus.delay-queue.%delay%',
        ];

        $delayConfig = DelayConfig::fromArray($config);

        // Percent placeholders should remain unchanged
        self::assertSame('message-bus.delay-queue.%delay%', $delayConfig->queueNamePattern);
    }

    public function testConstructorWithCurlyBracePlaceholders(): void
    {
        $delayConfig = new DelayConfig(
            queueNamePattern: 'message-bus.delay-queue.{delay}'
        );

        self::assertSame('message-bus.delay-queue.%delay%', $delayConfig->queueNamePattern);
    }

    public function testFromArrayWithNoPlaceholders(): void
    {
        $config = [
            'queue_name_pattern' => 'static-queue-name',
        ];

        $delayConfig = DelayConfig::fromArray($config);

        // Strings without placeholders should remain unchanged
        self::assertSame('static-queue-name', $delayConfig->queueNamePattern);
    }

    public function testFromArrayWithInvalidCurlyBraces(): void
    {
        $config = [
            'queue_name_pattern' => 'queue-{123invalid}-{valid_name}',
        ];

        $delayConfig = DelayConfig::fromArray($config);

        // Only valid identifiers should be converted (starts with letter or underscore)
        self::assertSame('queue-{123invalid}-%valid_name%', $delayConfig->queueNamePattern);
    }
}

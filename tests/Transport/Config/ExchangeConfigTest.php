<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ExchangeConfig;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PHPUnit\Framework\TestCase;
use stdClass;

class ExchangeConfigTest extends TestCase
{
    public function testDefaultConstruct(): void
    {
        $exchangeConfig = new ExchangeConfig();

        self::assertSame('', $exchangeConfig->name);
        self::assertSame(AMQPExchangeType::FANOUT, $exchangeConfig->type);
        self::assertSame(null, $exchangeConfig->defaultPublishRoutingKey);
        self::assertFalse($exchangeConfig->passive);
        self::assertTrue($exchangeConfig->durable);
        self::assertFalse($exchangeConfig->autoDelete);
        self::assertSame([], $exchangeConfig->arguments);
    }

    public function testFromArray(): void
    {
        $exchangeConfig = ExchangeConfig::fromArray([
            'name' => 'exchange_name',
            'type' => 'direct',
            'default_publish_routing_key' => 'routing_key',
            'passive' => true,
            'durable' => false,
            'auto_delete' => true,
            'arguments' => ['arg1' => 'val1', 'arg2' => 'val2'],
        ]);

        self::assertSame('exchange_name', $exchangeConfig->name);
        self::assertSame(AMQPExchangeType::DIRECT, $exchangeConfig->type);
        self::assertSame('routing_key', $exchangeConfig->defaultPublishRoutingKey);
        self::assertTrue($exchangeConfig->passive);
        self::assertFalse($exchangeConfig->durable);
        self::assertTrue($exchangeConfig->autoDelete);
        self::assertSame(['arg1' => 'val1', 'arg2' => 'val2'], $exchangeConfig->arguments);
    }

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidOptions(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid exchange option(s) "invalid" passed to the AMQP Messenger transport - known options: "name", "type", "default_publish_routing_key", "passive", "durable", "auto_delete", "arguments".');

        ExchangeConfig::fromArray(['invalid' => true]);
    }

    /** @psalm-suppress InvalidArgument */
    public function testFromArrayWithInvalidTypes(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "object" for key "name" (expected string)');

        ExchangeConfig::fromArray(['name' => new stdClass()]);
    }
}

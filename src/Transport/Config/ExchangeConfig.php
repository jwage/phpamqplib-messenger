<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;
use PhpAmqpLib\Exchange\AMQPExchangeType;

use function array_diff;
use function array_keys;
use function count;
use function implode;
use function sprintf;

readonly class ExchangeConfig
{
    private const array AVAILABLE_OPTIONS = [
        'name',
        'type',
        'default_publish_routing_key',
        'passive',
        'durable',
        'auto_delete',
        'arguments',
    ];

    public string $name;

    public string $type;

    public string|null $defaultPublishRoutingKey;

    public bool $passive;

    public bool $durable;

    public bool $autoDelete;

    /** @var array<string, mixed> */
    public array $arguments;

    /** @param array<string, mixed> $arguments */
    public function __construct(
        string|null $name = null,
        string|null $type = null,
        string|null $defaultPublishRoutingKey = null,
        bool|null $passive = null,
        bool|null $durable = null,
        bool|null $autoDelete = null,
        array|null $arguments = null,
    ) {
        $this->name                     = $name ?? '';
        $this->type                     = $type ?? AMQPExchangeType::FANOUT;
        $this->defaultPublishRoutingKey = $defaultPublishRoutingKey ?? null;
        $this->passive                  = $passive ?? false;
        $this->durable                  = $durable ?? true;
        $this->autoDelete               = $autoDelete ?? false;
        $this->arguments                = $arguments ?? [];
    }

    /**
     * @param array{
     *     name?: string,
     *     type?: string,
     *     default_publish_routing_key?: string,
     *     passive?: bool|mixed,
     *     durable?: bool|mixed,
     *     auto_delete?: bool|mixed,
     *     arguments?: array<string, mixed>,
     * } $exchangeConfig
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $exchangeConfig): self
    {
        self::validate($exchangeConfig);

        return new self(
            name: $exchangeConfig['name'] ?? null,
            type: $exchangeConfig['type'] ?? null,
            defaultPublishRoutingKey: $exchangeConfig['default_publish_routing_key'] ?? null,
            passive: isset($exchangeConfig['passive']) ? (bool) $exchangeConfig['passive'] : null,
            durable: isset($exchangeConfig['durable']) ? (bool) $exchangeConfig['durable'] : null,
            autoDelete: isset($exchangeConfig['auto_delete']) ? (bool) $exchangeConfig['auto_delete'] : null,
            arguments: $exchangeConfig['arguments'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $exchangeConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $exchangeConfig): void
    {
        if (0 < count($invalidExchangeOptions = array_diff(array_keys($exchangeConfig), self::AVAILABLE_OPTIONS))) {
            throw new InvalidArgumentException(sprintf('Invalid exchange option(s) "%s" passed to the AMQP Messenger transport.', implode('", "', $invalidExchangeOptions)));
        }
    }
}

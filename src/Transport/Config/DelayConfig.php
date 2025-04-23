<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;
use PhpAmqpLib\Exchange\AMQPExchangeType;

use function str_replace;

readonly class DelayConfig
{
    private const array AVAILABLE_OPTIONS = [
        'exchange',
        'enabled',
        'auto_setup',
        'queue_name_pattern',
        'arguments',
    ];

    public ExchangeConfig $exchange;

    public bool $enabled;

    public bool $autoSetup;

    public string $queueNamePattern;

    /** @var array<string, mixed> */
    public array $arguments;

    /** @param array<string, mixed> $arguments */
    public function __construct(
        ExchangeConfig|null $exchange = null,
        bool|null $enabled = null,
        bool|null $autoSetup = null,
        string|null $queueNamePattern = null,
        array|null $arguments = [],
    ) {
        $this->exchange = $exchange ?? new ExchangeConfig(
            name: 'delays',
            type: AMQPExchangeType::DIRECT,
        );

        $this->enabled          = $enabled ?? true;
        $this->autoSetup        = $autoSetup ?? true;
        $this->queueNamePattern = $queueNamePattern ?? 'delay_%exchange_name%_%routing_key%_%delay%';
        $this->arguments        = $arguments ?? [];
    }

    /**
     * @param array{
     *     exchange?: array{
     *         name?: string,
     *         default_publish_routing_key?: string,
     *         type?: string,
     *         passive?: bool|mixed,
     *         durable?: bool|mixed,
     *         auto_delete?: bool|mixed,
     *         arguments?: array<string, mixed>,
     *     },
     *     enabled?: bool|mixed,
     *     auto_setup?: bool|mixed,
     *     queue_name_pattern?: string,
     *     arguments?: array<string, mixed>,
     * } $delayConfig
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $delayConfig): self
    {
        self::validate($delayConfig);

        return new self(
            exchange: isset($delayConfig['exchange']) ? ExchangeConfig::fromArray($delayConfig['exchange']) : null,
            enabled: ConfigHelper::getBool($delayConfig, 'enabled'),
            autoSetup: ConfigHelper::getBool($delayConfig, 'auto_setup'),
            queueNamePattern: ConfigHelper::getString($delayConfig, 'queue_name_pattern'),
            arguments: ConfigHelper::getArray($delayConfig, 'arguments'),
        );
    }

    public function getQueueName(int $delay, string|null $routingKey, bool $isRetryAttempt): string
    {
        $action = $isRetryAttempt ? '_retry' : '_delay';

        return str_replace(
            ['%delay%', '%exchange_name%', '%routing_key%'],
            [
                $delay,
                $this->exchange->name,
                $routingKey ?? '',
            ],
            $this->queueNamePattern,
        ) . $action;
    }

    /**
     * @param array<string, mixed> $delayConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $delayConfig): void
    {
        ConfigHelper::validate('delay', $delayConfig, self::AVAILABLE_OPTIONS);
    }
}

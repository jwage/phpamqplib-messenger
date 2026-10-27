<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;
use PhpAmqpLib\Exchange\AMQPExchangeType;

readonly class DelayConfig
{
    private const array AVAILABLE_OPTIONS = [
        'exchange',
        'enabled',
        'auto_setup',
        'queue_name_pattern',
        'durable',
        'daily_delay_queues',
        'arguments',
    ];

    public ExchangeConfig $exchange;

    public bool $enabled;

    public bool $autoSetup;

    public string $queueNamePattern;

    public bool $durable;

    public bool $dailyDelayQueues;

    /**
     * Extra delay queue arguments. The generated `x-message-ttl`, `x-expires`,
     * `x-dead-letter-exchange` and `x-dead-letter-routing-key` always take precedence.
     *
     * @var array<string, mixed>
     */
    public array $arguments;

    /** @param array<string, mixed> $arguments */
    public function __construct(
        ExchangeConfig|null $exchange = null,
        bool|null $enabled = null,
        bool|null $autoSetup = null,
        string|null $queueNamePattern = null,
        bool|null $durable = null,
        bool|null $dailyDelayQueues = null,
        array|null $arguments = [],
    ) {
        $this->exchange = $exchange ?? new ExchangeConfig(
            name: 'delays',
            type: AMQPExchangeType::DIRECT,
        );

        $this->enabled          = $enabled ?? true;
        $this->autoSetup        = $autoSetup ?? true;
        $this->queueNamePattern = $queueNamePattern ?? 'delay_%exchange_name%_%routing_key%_%delay%';
        $this->durable          = $durable ?? false;
        $this->dailyDelayQueues = $dailyDelayQueues ?? false;
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
     *     durable?: bool|mixed,
     *     daily_delay_queues?: bool|mixed,
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
            durable: ConfigHelper::getBool($delayConfig, 'durable'),
            dailyDelayQueues: ConfigHelper::getBool($delayConfig, 'daily_delay_queues'),
            arguments: ConfigHelper::getArray($delayConfig, 'arguments'),
        );
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

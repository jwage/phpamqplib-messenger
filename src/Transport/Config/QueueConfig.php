<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;

use function array_diff;
use function array_keys;
use function count;
use function implode;
use function is_string;
use function sprintf;

final readonly class QueueConfig
{
    private const array AVAILABLE_OPTIONS = [
        'name',
        'prefetch_count',
        'wait_timeout',
        'passive',
        'durable',
        'exclusive',
        'auto_delete',
        'bindings',
        'arguments',
    ];

    public string $name;

    public int $prefetchCount;

    public int|float|null $waitTimeout;

    public bool $passive;

    public bool $durable;

    public bool $exclusive;

    public bool $autoDelete;

    /** @var array<string, BindingConfig> */
    public array $bindings;

    /** @var array<string, mixed> */
    public array $arguments;

    /**
     * @param array<int|string, BindingConfig> $bindings
     * @param array<string, mixed>             $arguments
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        string|null $name = null,
        int|null $prefetchCount = null,
        int|float|null $waitTimeout = null,
        bool|null $passive = null,
        bool|null $durable = null,
        bool|null $exclusive = null,
        bool|null $autoDelete = null,
        array|null $bindings = null,
        array|null $arguments = null,
    ) {
        if ($name === null || $name === '') {
            throw new InvalidArgumentException('Queue name is required');
        }

        $this->name          = $name;
        $this->prefetchCount = $prefetchCount ?? ConnectionConfig::DEFAULT_PREFETCH_COUNT;
        $this->waitTimeout   = $waitTimeout ?? ConnectionConfig::DEFAULT_WAIT_TIMEOUT;
        $this->passive       = $passive ?? false;
        $this->durable       = $durable ?? true;
        $this->exclusive     = $exclusive ?? false;
        $this->autoDelete    = $autoDelete ?? false;
        $this->bindings      = self::indexByRoutingKey($bindings ?? []);
        $this->arguments     = $arguments ?? [];
    }

    /**
     * @param array{
     *     name?: string,
     *     prefetch_count?: int|mixed,
     *     wait_timeout?: int|float|mixed,
     *     passive?: bool|mixed,
     *     durable?: bool|mixed,
     *     exclusive?: bool|mixed,
     *     auto_delete?: bool|mixed,
     *     bindings?: array<int|string, array{
     *         routing_key?: string,
     *         arguments?: array<string, mixed>,
     *     }|null>,
     *     arguments?: array<string, mixed>,
     * } $queueConfig
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $queueConfig): self
    {
        self::validate($queueConfig);

        $bindings = $queueConfig['bindings'] ?? [];

        $bindingConfigs = [];

        foreach ($bindings as $routingKey => $binding) {
            $binding ??= [];

            if (! isset($binding['routing_key']) && is_string($routingKey)) {
                $binding['routing_key'] = $routingKey;
            }

            $routingKey = $binding['routing_key'] ?? '';

            $bindingConfigs[$routingKey] = BindingConfig::fromArray($binding);
        }

        return new self(
            name: $queueConfig['name'] ?? null,
            prefetchCount: isset($queueConfig['prefetch_count']) ? (int) $queueConfig['prefetch_count'] : null,
            waitTimeout: isset($queueConfig['wait_timeout']) ? (float) $queueConfig['wait_timeout'] : null,
            passive: isset($queueConfig['passive']) ? (bool) $queueConfig['passive'] : null,
            durable: isset($queueConfig['durable']) ? (bool) $queueConfig['durable'] : null,
            exclusive: isset($queueConfig['exclusive']) ? (bool) $queueConfig['exclusive'] : null,
            autoDelete: isset($queueConfig['auto_delete']) ? (bool) $queueConfig['auto_delete'] : null,
            bindings: $bindingConfigs,
            arguments: $queueConfig['arguments'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $queueConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $queueConfig): void
    {
        if (0 < count($invalidQueueOptions = array_diff(array_keys($queueConfig), self::AVAILABLE_OPTIONS))) {
            throw new InvalidArgumentException(sprintf('Invalid queue option(s) "%s" passed to the AMQP Messenger transport.', implode('", "', $invalidQueueOptions)));
        }
    }

    /**
     * @param array<int|string, BindingConfig> $bindings
     *
     * @return array<string, BindingConfig>
     */
    private static function indexByRoutingKey(array $bindings): array
    {
        $indexedBindings = [];

        foreach ($bindings as $key => $binding) {
            if (is_string($key) && $binding->routingKey !== $key) {
                throw new InvalidArgumentException(sprintf(
                    'Binding routing key "%s" does not match array key "%s"',
                    $binding->routingKey,
                    $key,
                ));
            }

            $indexedBindings[$binding->routingKey] = $binding;
        }

        return $indexedBindings;
    }
}

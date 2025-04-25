<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;

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
        'binding_keys',
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

        if ($waitTimeout === 0 || $waitTimeout === 0.0) {
            throw new InvalidArgumentException(sprintf('Queue "%s" wait timeout cannot be zero. This will cause the consumer to wait forever and block the messenger worker loop.', $name));
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
     *     binding_keys?: array<string>,
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

        if (isset($queueConfig['bindings']) && isset($queueConfig['binding_keys'])) {
            throw new InvalidArgumentException('Invalid queue config: "bindings" and "binding_keys" cannot both be set. binding_keys is only available for compatibility with symfony/amqp-messenger. It is recommended to use "bindings" instead.');
        }

        if (isset($queueConfig['binding_keys'])) {
            /** @var array<string> $bindingKeys */
            $bindingKeys = ConfigHelper::getArray($queueConfig, 'binding_keys') ?? [];

            $queueConfig['bindings'] = [];

            foreach ($bindingKeys as $bindingKey) {
                $queueConfig['bindings'][] = ['routing_key' => $bindingKey];
            }
        }

        /**
         * @var array<int|string, array{
         *     routing_key?: string,
         *     arguments?: array<string, mixed>,
         * }|null> $bindings
         */
        $bindings = ConfigHelper::getArray($queueConfig, 'bindings') ?? [];

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
            name: ConfigHelper::getString($queueConfig, 'name'),
            prefetchCount: ConfigHelper::getInt($queueConfig, 'prefetch_count'),
            waitTimeout: ConfigHelper::getFloat($queueConfig, 'wait_timeout'),
            passive: ConfigHelper::getBool($queueConfig, 'passive'),
            durable: ConfigHelper::getBool($queueConfig, 'durable'),
            exclusive: ConfigHelper::getBool($queueConfig, 'exclusive'),
            autoDelete: ConfigHelper::getBool($queueConfig, 'auto_delete'),
            bindings: $bindingConfigs,
            arguments: ConfigHelper::getArray($queueConfig, 'arguments'),
        );
    }

    /**
     * @param array<string, mixed> $queueConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $queueConfig): void
    {
        ConfigHelper::validate('queue', $queueConfig, self::AVAILABLE_OPTIONS);
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

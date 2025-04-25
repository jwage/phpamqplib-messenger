<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use SensitiveParameter;

use function array_keys;
use function is_string;
use function sprintf;

readonly class ConnectionConfig
{
    public const int DEFAULT_PREFETCH_COUNT = 1;

    public const int DEFAULT_WAIT_TIMEOUT = 1;

    public const int DEFAULT_CONFIRM_TIMEOUT = 3;

    private const array AVAILABLE_OPTIONS = [
        'auto_setup',
        'host',
        'port',
        'user',
        'login',
        'password',
        'vhost',
        'insist',
        'login_method',
        'locale',
        'connection_timeout',
        'read_timeout',
        'write_timeout',
        'rpc_timeout',
        'heartbeat',
        'keepalive',
        'prefetch_count',
        'wait_timeout',
        'confirm_enabled',
        'confirm_timeout',
        'transactions_enabled',
        'ssl',
        'exchange',
        'delay',
        'queues',
    ];

    public bool $autoSetup;

    public string $host;

    public int $port;

    public string $user;

    public string $password;

    public string $vhost;

    public bool $insist;

    public string $loginMethod;

    public string $locale;

    public float $connectionTimeout;

    public float $readTimeout;

    public float $writeTimeout;

    public float $rpcTimeout;

    public int $heartbeat;

    public bool $keepalive;

    public int $prefetchCount;

    public int|float|null $waitTimeout;

    public bool $confirmEnabled;

    public int|float $confirmTimeout;

    public bool $transactionsEnabled;

    public ExchangeConfig $exchange;

    public DelayConfig $delay;

    /** @var array<string, QueueConfig> */
    public array $queues;

    /**
     * @param array<int|string, QueueConfig> $queues
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        bool|null $autoSetup = null,
        string|null $host = null,
        int|null $port = null,
        string|null $user = null,
        #[SensitiveParameter]
        string|null $password = null,
        string|null $vhost = null,
        bool|null $insist = null,
        string|null $loginMethod = null,
        string|null $locale = null,
        float|null $connectionTimeout = null,
        float|null $readTimeout = null,
        float|null $writeTimeout = null,
        float|null $rpcTimeout = null,
        int|null $heartbeat = null,
        bool|null $keepalive = null,
        int|null $prefetchCount = null,
        int|float|null $waitTimeout = null,
        bool|null $confirmEnabled = null,
        int|float|null $confirmTimeout = null,
        bool|null $transactionsEnabled = null,
        public SslConfig|null $ssl = null,
        ExchangeConfig|null $exchange = null,
        DelayConfig|null $delay = null,
        array|null $queues = null,
    ) {
        if ($waitTimeout === 0 || $waitTimeout === 0.0) {
            throw new InvalidArgumentException('Connection wait timeout cannot be zero. This will cause the consumer to wait forever and block the messenger worker loop.');
        }

        if ($transactionsEnabled && $confirmEnabled) {
            throw new InvalidArgumentException('Transactions and confirms cannot be enabled at the same time. You must choose one.');
        }

        $this->autoSetup           = $autoSetup ?? true;
        $this->host                = $host ?? 'localhost';
        $this->port                = $port ?? 5672;
        $this->user                = $user ?? 'guest';
        $this->password            = $password ?? 'guest';
        $this->vhost               = $vhost ?? '/';
        $this->insist              = $insist ?? false;
        $this->loginMethod         = $loginMethod ?? AMQPConnectionConfig::AUTH_AMQPPLAIN;
        $this->locale              = $locale ?? 'en_US';
        $this->connectionTimeout   = $connectionTimeout ?? 3.0;
        $this->readTimeout         = $readTimeout ?? 3.0;
        $this->writeTimeout        = $writeTimeout ?? 3.0;
        $this->rpcTimeout          = $rpcTimeout ?? 3.0;
        $this->heartbeat           = $heartbeat ?? 0;
        $this->keepalive           = $keepalive ?? true;
        $this->prefetchCount       = $prefetchCount ?? self::DEFAULT_PREFETCH_COUNT;
        $this->waitTimeout         = $waitTimeout ?? self::DEFAULT_WAIT_TIMEOUT;
        $this->confirmEnabled      = $confirmEnabled ?? true;
        $this->confirmTimeout      = $confirmTimeout ?? self::DEFAULT_CONFIRM_TIMEOUT;
        $this->transactionsEnabled = $transactionsEnabled ?? false;
        $this->exchange            = $exchange ?? new ExchangeConfig();
        $this->delay               = $delay ?? new DelayConfig();
        $this->queues              = self::indexByQueueName($queues ?? []);
    }

    /**
     * @param array{
     *     auto_setup?: bool,
     *     host?: string,
     *     port?: int|mixed,
     *     user?: string,
     *     login?: string,
     *     password?: string,
     *     vhost?: string,
     *     insist?: bool|mixed,
     *     login_method?: string,
     *     locale?: string,
     *     connection_timeout?: float|mixed,
     *     read_timeout?: float|mixed,
     *     write_timeout?: float|mixed,
     *     rpc_timeout?: float|mixed,
     *     heartbeat?: int|mixed,
     *     keepalive?: bool|mixed,
     *     prefetch_count?: int|mixed,
     *     wait_timeout?: int|float|mixed,
     *     confirm_enabled?: bool|mixed,
     *     confirm_timeout?: int|float|mixed,
     *     transactions_enabled?: bool|mixed,
     *     ssl?: array{
     *         cafile?: string|null,
     *         capath?: string|null,
     *         local_cert?: string|null,
     *         local_pk?: string|null,
     *         verify_peer?: bool|null,
     *         verify_peer_name?: bool|null,
     *         passphrase?: string|null,
     *         ciphers?: string|null,
     *         security_level?: int|null,
     *         crypto_method?: int|null,
     *     },
     *     exchange?: array{
     *         name?: string,
     *         default_publish_routing_key?: string,
     *         type?: string,
     *         passive?: bool|mixed,
     *         durable?: bool|mixed,
     *         auto_delete?: bool|mixed,
     *         arguments?: array<string, mixed>,
     *     },
     *     delay?: array{
     *         exchange?: array{
     *             name?: string,
     *             default_publish_routing_key?: string,
     *             type?: string,
     *             passive?: bool|mixed,
     *             durable?: bool|mixed,
     *             auto_delete?: bool|mixed,
     *             arguments?: array<string, mixed>,
     *         },
     *         queue_name_pattern?: string,
     *     },
     *     queues?: array<int|string, array{
     *         name?: string,
     *         prefetch_count?: int|mixed,
     *         wait_timeout?: int|float|mixed,
     *         passive?: bool|mixed,
     *         durable?: bool|mixed,
     *         exclusive?: bool|mixed,
     *         auto_delete?: bool|mixed,
     *         bindings?: array<int|string, array{
     *             routing_key?: string,
     *             arguments?: array<string, mixed>,
     *         }|null>,
     *         arguments?: array<string, mixed>,
     *     }|null>,
     * } $connectionConfig
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $connectionConfig): self
    {
        self::validate($connectionConfig);

        $prefetchCount = ConfigHelper::getInt($connectionConfig, 'prefetch_count');
        $waitTimeout   = ConfigHelper::getFloat($connectionConfig, 'wait_timeout');

        /**
         * @var array<int|string, array{
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
         * }|null> $queues
         */
        $queues = ConfigHelper::getArray($connectionConfig, 'queues') ?? [];

        $queueConfigs = [];

        foreach ($queues as $queueName => $queue) {
            $queue ??= [];

            if (! isset($queue['name']) && is_string($queueName)) {
                $queue['name'] = $queueName;
            }

            if ($prefetchCount !== null && ! isset($queue['prefetch_count'])) {
                $queue['prefetch_count'] = $prefetchCount;
            }

            if ($waitTimeout !== null && ! isset($queue['wait_timeout'])) {
                $queue['wait_timeout'] = $waitTimeout;
            }

            $queueName = $queue['name'] ?? '';

            $queueConfigs[$queueName] = QueueConfig::fromArray($queue);
        }

        return new self(
            autoSetup: ConfigHelper::getBool($connectionConfig, 'auto_setup'),
            host: ConfigHelper::getString($connectionConfig, 'host'),
            port: ConfigHelper::getInt($connectionConfig, 'port'),
            // Support both user and login for compatibility with symfony/amqp-messenger
            user: ConfigHelper::getString($connectionConfig, 'user') ?? ConfigHelper::getString($connectionConfig, 'login'),
            password: ConfigHelper::getString($connectionConfig, 'password'),
            vhost: ConfigHelper::getString($connectionConfig, 'vhost'),
            insist: ConfigHelper::getBool($connectionConfig, 'insist'),
            loginMethod: ConfigHelper::getString($connectionConfig, 'login_method'),
            locale: ConfigHelper::getString($connectionConfig, 'locale'),
            connectionTimeout: ConfigHelper::getFloat($connectionConfig, 'connection_timeout'),
            readTimeout: ConfigHelper::getFloat($connectionConfig, 'read_timeout'),
            writeTimeout: ConfigHelper::getFloat($connectionConfig, 'write_timeout'),
            rpcTimeout: ConfigHelper::getFloat($connectionConfig, 'rpc_timeout'),
            heartbeat: ConfigHelper::getInt($connectionConfig, 'heartbeat'),
            keepalive: ConfigHelper::getBool($connectionConfig, 'keepalive'),
            prefetchCount: $prefetchCount,
            waitTimeout: $waitTimeout,
            confirmEnabled: ConfigHelper::getBool($connectionConfig, 'confirm_enabled'),
            confirmTimeout: ConfigHelper::getFloat($connectionConfig, 'confirm_timeout'),
            transactionsEnabled: ConfigHelper::getBool($connectionConfig, 'transactions_enabled'),
            ssl: isset($connectionConfig['ssl']) ? SslConfig::fromArray($connectionConfig['ssl']) : null,
            exchange: isset($connectionConfig['exchange']) ? ExchangeConfig::fromArray($connectionConfig['exchange']) : null,
            delay: isset($connectionConfig['delay']) ? DelayConfig::fromArray($connectionConfig['delay']) : null,
            queues: $queueConfigs,
        );
    }

    /** @return array<string> */
    public function getQueueNames(): array
    {
        return array_keys($this->queues);
    }

    /** @throws InvalidArgumentException */
    public function getQueueConfig(string $queueName): QueueConfig
    {
        if (! isset($this->queues[$queueName])) {
            throw new InvalidArgumentException(sprintf('Queue "%s" not found', $queueName));
        }

        return $this->queues[$queueName];
    }

    /**
     * @param array<string, mixed> $connectionConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $connectionConfig): void
    {
        ConfigHelper::validate('connection', $connectionConfig, self::AVAILABLE_OPTIONS);
    }

    /**
     * @param array<int|string, QueueConfig> $queues
     *
     * @return array<string, QueueConfig>
     */
    private static function indexByQueueName(array $queues): array
    {
        $indexedQueues = [];

        foreach ($queues as $key => $queue) {
            if (is_string($key) && $queue->name !== $key) {
                throw new InvalidArgumentException(sprintf(
                    'Queue name "%s" does not match array key "%s"',
                    $queue->name,
                    $key,
                ));
            }

            $indexedQueues[$queue->name] = $queue;
        }

        return $indexedQueues;
    }
}

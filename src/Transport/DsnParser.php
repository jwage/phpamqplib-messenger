<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;

use function array_key_exists;
use function array_map;
use function array_replace_recursive;
use function explode;
use function get_debug_type;
use function is_array;
use function is_numeric;
use function parse_str;
use function parse_url;
use function rawurldecode;
use function sprintf;
use function str_starts_with;
use function trim;
use function urldecode;

class DsnParser
{
    private const array INTEGER_ARGUMENTS = [
        'x-delay',
        'x-expires',
        'x-max-length',
        'x-max-length-bytes',
        'x-max-priority',
        'x-message-ttl',
    ];

    /**
     * @param array<array-key, mixed> $options
     *
     * @throws InvalidArgumentException
     */
    public function parseDsn(string $dsn, array $options = []): ConnectionConfig
    {
        $params = parse_url($dsn);

        $useAmqps = str_starts_with($dsn, 'phpamqplibs://');

        $pathParts = isset($params['path']) ? explode('/', trim($params['path'], '/')) : [];

        $exchangeName = $pathParts[1] ?? 'messages';

        parse_str($params['query'] ?? '', $parsedQuery);

        $port = $useAmqps ? 5671 : 5672;

        /**
         * @var array{
         *     auto_setup?: bool,
         *     host?: string,
         *     port?: int|mixed,
         *     user?: string,
         *     password?: string,
         *     vhost?: string,
         *     insist?: bool|mixed,
         *     login_method?: string,
         *     locale?: string,
         *     connect_timeout?: float|mixed,
         *     read_timeout?: float|mixed,
         *     write_timeout?: float|mixed,
         *     rpc_timeout?: float|mixed,
         *     heartbeat?: int|mixed,
         *     keepalive?: bool|mixed,
         *     prefetch_count?: int|mixed,
         *     wait_timeout?: int|float|mixed,
         *     confirm_enabled?: bool|mixed,
         *     confirm_timeout?: int|float|mixed,
         *     ssl: array{
         *         cafile?: string,
         *         capath?: string,
         *         local_cert?: string,
         *         local_pk?: string,
         *         verify_peer?: bool,
         *         verify_peer_name?: bool,
         *         passphrase?: string,
         *         ciphers?: string,
         *         security_level?: int,
         *         crypto_method?: int,
         *     },
         *     exchange: array{
         *         name: string,
         *         default_publish_routing_key?: string,
         *         type?: string,
         *         passive?: bool,
         *         durable?: bool,
         *         auto_delete?: bool,
         *         arguments?: array<string, mixed>,
         *     },
         *     delay?: array{
         *         exchange?: array{
         *             name?: string,
         *             default_publish_routing_key?: string,
         *             type?: string,
         *             passive?: bool,
         *             durable?: bool,
         *             auto_delete?: bool,
         *             arguments?: array<string, mixed>,
         *         },
         *         queue_name_pattern?: string,
         *     },
         *     queues: array<string, array{
         *         passive?: bool,
         *         durable?: bool,
         *         exclusive?: bool,
         *         auto_delete?: bool,
         *         bindings?: array<int|string, array{
         *             routing_key?: string,
         *             arguments?: array<string, mixed>,
         *         }>,
         *         arguments?: array<string, mixed>,
         *     }|null>,
         *     connection_name?: string,
         * } $connectionConfig
         */
        $connectionConfig = array_replace_recursive([
            'host' => $params['host'] ?? 'localhost',
            'port' => $params['port'] ?? $port,
            'vhost' => isset($pathParts[0]) ? urldecode($pathParts[0]) : '/',
            'exchange' => ['name' => $exchangeName],
        ], $options, $parsedQuery);

        if (isset($params['user'])) {
            $connectionConfig['user'] = rawurldecode($params['user']);
        }

        if (isset($params['pass'])) {
            $connectionConfig['password'] = rawurldecode($params['pass']);
        }

        if (isset($connectionConfig['queues'])) {
            $connectionConfig['queues'] = self::buildQueuesOptions($connectionConfig['queues']);
        } else {
            $connectionConfig['queues'] = [$exchangeName => []];
        }

        if (! $useAmqps) {
            unset($connectionConfig['ssl']);
        }

        if ($useAmqps && ! self::hasSslConfigured($connectionConfig)) {
            throw new InvalidArgumentException('No ssl configuration has been provided. Alternatively, you can use phpamqplib:// to use without SSL.');
        }

        return ConnectionConfig::fromArray($connectionConfig);
    }

    /**
     * @param array<string, array{
     *     passive?: bool,
     *     durable?: bool,
     *     exclusive?: bool,
     *     auto_delete?: bool,
     *     bindings?: array<int|string, array{
     *         routing_key?: string,
     *         arguments?: array<string, mixed>,
     *     }>,
     *     arguments?: array<string, mixed>
     * }|null> $queues
     *
     * @return array<string, array{
     *     passive?: bool,
     *     durable?: bool,
     *     exclusive?: bool,
     *     auto_delete?: bool,
     *     bindings?: array<int|string, array{
     *         routing_key?: string,
     *         arguments?: array<string, mixed>,
     *     }>,
     *     arguments?: array<string, mixed>
     * }>
     */
    private static function buildQueuesOptions(array $queues): array
    {
        return array_map(static function (mixed $queueOptions) {
            if (! is_array($queueOptions)) {
                $queueOptions = [];
            }

            if (isset($queueOptions['arguments'])) {
                $queueOptions['arguments'] = self::normalizeQueueArguments($queueOptions['arguments']);
            }

            return $queueOptions;
        }, $queues);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function normalizeQueueArguments(array $arguments): array
    {
        foreach (self::INTEGER_ARGUMENTS as $key) {
            if (! array_key_exists($key, $arguments)) {
                continue;
            }

            if (! is_numeric($arguments[$key])) {
                throw new InvalidArgumentException(sprintf('Integer expected for queue argument "%s", "%s" given.', $key, get_debug_type($arguments[$key])));
            }

            $arguments[$key] = (int) $arguments[$key];
        }

        return $arguments;
    }

    /** @param array<string, mixed> $connectionConfig */
    private static function hasSslConfigured(array $connectionConfig): bool
    {
        return isset($connectionConfig['ssl']) && ! empty($connectionConfig['ssl']);
    }
}

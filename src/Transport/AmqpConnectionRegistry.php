<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/**
 * Process-local registry for shared {@see AMQPStreamConnection} instances.
 *
 * Whether connections are shared is controlled by {@see AmqpConnectionRegistryKey} (bundle default
 * {@see AmqpConnectionReuse::NONE} for production isolation; CloudAMQP-style setups often use
 * separate producer and consumer TCP connections — use {@see AmqpConnectionReuse::PRODUCER_CONSUMER}
 * or {@see AmqpConnectionReuse::NONE}). Sharing reduces TCP/TLS overhead but producer and consumer
 * can cross-impact under broker flow control or connection alarms.
 */
final class AmqpConnectionRegistry
{
    /** @var array<string, array{connection: AMQPStreamConnection, generation: int}> */
    private array $entries = [];

    public function __construct(
        private AmqpConnectionFactory $amqpConnectionFactory,
    ) {
    }

    public function get(AmqpConnectionRegistryKey $registryKey, ConnectionConfig $connectionConfig): AMQPStreamConnection
    {
        $key = $registryKey->toString();

        if (! isset($this->entries[$key])) {
            $this->entries[$key] = [
                'connection' => $this->amqpConnectionFactory->create($connectionConfig),
                'generation' => 0,
            ];
        }

        return $this->entries[$key]['connection'];
    }

    public function generation(AmqpConnectionRegistryKey $registryKey): int
    {
        $entry = $this->entries[$registryKey->toString()] ?? null;

        return $entry['generation'] ?? 0;
    }

    public function reconnect(AmqpConnectionRegistryKey $registryKey, ConnectionConfig $connectionConfig): void
    {
        $key = $registryKey->toString();

        if (isset($this->entries[$key])) {
            try {
                $this->entries[$key]['connection']->close();
            } catch (Throwable) {
            }

            $generation = $this->entries[$key]['generation'] + 1;
        } else {
            $generation = 0;
        }

        $this->entries[$key] = [
            'connection' => $this->amqpConnectionFactory->create($connectionConfig),
            'generation' => $generation,
        ];
    }
}

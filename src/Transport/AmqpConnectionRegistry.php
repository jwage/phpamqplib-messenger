<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

final class AmqpConnectionRegistry
{
    /** @var array<string, array{connection: AMQPStreamConnection, generation: int}> */
    private array $entries = [];

    public function __construct(
        private AmqpConnectionFactory $amqpConnectionFactory,
    ) {
    }

    public function get(AmqpConnectionIdentity $identity, ConnectionConfig $connectionConfig): AMQPStreamConnection
    {
        $key = $identity->toString();

        if (! isset($this->entries[$key])) {
            $this->entries[$key] = [
                'connection' => $this->amqpConnectionFactory->create($connectionConfig),
                'generation' => 0,
            ];
        }

        return $this->entries[$key]['connection'];
    }

    public function generation(AmqpConnectionIdentity $identity): int
    {
        $entry = $this->entries[$identity->toString()] ?? null;

        return $entry['generation'] ?? 0;
    }

    public function reconnect(AmqpConnectionIdentity $identity, ConnectionConfig $connectionConfig): void
    {
        $key = $identity->toString();

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

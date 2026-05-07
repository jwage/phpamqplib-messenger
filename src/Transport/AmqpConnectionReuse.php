<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;

use function sprintf;

/**
 * Controls when low-level AMQP TCP connections are shared across {@see Connection} wrappers.
 *
 * Separate TCP connections (none) give the best production isolation (e.g. CloudAMQP recommends
 * separate producer and consumer connections). Sharing (all) reduces TCP/TLS overhead but
 * producer and consumer can cross-impact under broker flow control or connection alarms.
 */
enum AmqpConnectionReuse: string
{
    /**
     * Never share: each {@see Connection} gets its own TCP connection (safest isolation).
     */
    case NONE = 'none';

    /**
     * Share only within the same {@see AmqpConnectionRole}: producers with producers,
     * consumers with consumers. Producer pools never share with consumer pools.
     */
    case PRODUCER_CONSUMER = 'producer-consumer';

    /**
     * Share one TCP connection for the same low-level broker identity regardless of role
     * (most efficient, lowest isolation).
     */
    case ALL = 'all';

    /** @throws InvalidArgumentException */
    public static function fromConfigString(string $value): self
    {
        $normalized = self::tryFrom($value);

        if ($normalized === null) {
            throw new InvalidArgumentException(sprintf(
                'Invalid connection_reuse "%s". Expected one of: none, producer-consumer, all.',
                $value,
            ));
        }

        return $normalized;
    }
}

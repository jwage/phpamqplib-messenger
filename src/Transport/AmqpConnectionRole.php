<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;

use function sprintf;

/**
 * Transport role for connection sharing when {@see AmqpConnectionReuse::PRODUCER_CONSUMER}.
 *
 * For strict producer/consumer isolation (recommended by CloudAMQP-style guidance), define two
 * Messenger transports with the same broker DSN: one with {@see self::CONSUMER} for the worker
 * transport and one with {@see self::PRODUCER} for dispatching outbound messages. When
 * {@see AmqpConnectionReuse::ALL}, roles do not affect TCP sharing.
 *
 * A single {@see AmqpTransport} that both consumes and publishes uses one {@see Connection} and
 * should set {@see self::MIXED}; it only shares TCP with other mixed-role wrappers in
 * {@see AmqpConnectionReuse::PRODUCER_CONSUMER} mode.
 */
enum AmqpConnectionRole: string
{
    case PRODUCER = 'producer';

    case CONSUMER = 'consumer';

    /**
     * Both consume and publish on the same transport {@see Connection} (one channel).
     */
    case MIXED = 'mixed';

    /**
     * @throws InvalidArgumentException
     */
    public static function fromConfigString(string $value): self
    {
        $normalized = self::tryFrom($value);

        if ($normalized === null) {
            throw new InvalidArgumentException(sprintf(
                'Invalid connection_role "%s". Expected one of: producer, consumer, mixed.',
                $value,
            ));
        }

        return $normalized;
    }
}

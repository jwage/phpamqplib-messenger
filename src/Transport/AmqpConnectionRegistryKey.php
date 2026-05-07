<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use InvalidArgumentException;

use function hash;

/**
 * Registry map key: low-level broker identity + reuse policy (+ role / dedicated id when required).
 */
final readonly class AmqpConnectionRegistryKey
{
    private function __construct(
        private string $key,
    ) {
    }

    /**
     * @param non-empty-string $dedicatedInstanceId Required when {@see AmqpConnectionReuse::NONE}
     *                                              (unique per {@see Connection} wrapper). Empty otherwise.
     *
     * @throws InvalidArgumentException
     */
    public static function create(
        AmqpConnectionIdentity $brokerIdentity,
        AmqpConnectionReuse $reuse,
        AmqpConnectionRole $role,
        string $dedicatedInstanceId,
    ): self {
        $broker = $brokerIdentity->toString();

        return new self(match ($reuse) {
            AmqpConnectionReuse::NONE => $dedicatedInstanceId === ''
                ? throw new InvalidArgumentException(
                    'connection_reuse=none requires a non-empty dedicated instance id per Connection.',
                )
                : hash('sha256', $broker . "\0" . $reuse->value . "\0" . $dedicatedInstanceId),
            AmqpConnectionReuse::ALL => $dedicatedInstanceId !== ''
                ? throw new InvalidArgumentException(
                    'Dedicated instance id must be empty when connection_reuse is not none.',
                )
                : hash('sha256', $broker . "\0" . $reuse->value),
            AmqpConnectionReuse::PRODUCER_CONSUMER => $dedicatedInstanceId !== ''
                ? throw new InvalidArgumentException(
                    'Dedicated instance id must be empty when connection_reuse is not none.',
                )
                : hash('sha256', $broker . "\0" . $reuse->value . "\0" . $role->value),
        });
    }

    public function toString(): string
    {
        return $this->key;
    }
}

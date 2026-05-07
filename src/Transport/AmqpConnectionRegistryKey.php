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
     * @param non-empty-string|null $dedicatedInstanceId Non-empty string when {@see AmqpConnectionReuse::NONE}
     *                                                   (unique per {@see Connection} wrapper). Null when reuse is not none.
     *
     * @throws InvalidArgumentException
     */
    public static function create(
        AmqpConnectionIdentity $brokerIdentity,
        AmqpConnectionReuse $reuse,
        AmqpConnectionRole $role,
        string|null $dedicatedInstanceId,
    ): self {
        $broker = $brokerIdentity->toString();

        return new self(match ($reuse) {
            AmqpConnectionReuse::NONE => hash(
                'sha256',
                $broker . "\0" . $reuse->value . "\0" . self::requireNonEmptyDedicatedInstanceIdForNone($dedicatedInstanceId),
            ),
            AmqpConnectionReuse::ALL => $dedicatedInstanceId !== null
                ? throw new InvalidArgumentException(
                    'Dedicated instance id must be null when connection_reuse is not none.',
                )
                : hash('sha256', $broker . "\0" . $reuse->value),
            AmqpConnectionReuse::PRODUCER_CONSUMER => $dedicatedInstanceId !== null
                ? throw new InvalidArgumentException(
                    'Dedicated instance id must be null when connection_reuse is not none.',
                )
                : hash('sha256', $broker . "\0" . $reuse->value . "\0" . $role->value),
        });
    }

    public function toString(): string
    {
        return $this->key;
    }

    /**
     * @return non-empty-string
     *
     * @throws InvalidArgumentException
     */
    private static function requireNonEmptyDedicatedInstanceIdForNone(string|null $id): string
    {
        if ($id === null || $id === '') {
            throw new InvalidArgumentException(
                'connection_reuse=none requires a non-empty dedicated instance id per Connection.',
            );
        }

        return $id;
    }
}

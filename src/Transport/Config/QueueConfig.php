<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;

use function array_diff;
use function array_keys;
use function count;
use function implode;
use function sprintf;

final readonly class QueueConfig
{
    private const array AVAILABLE_OPTIONS = [
        'passive',
        'durable',
        'exclusive',
        'auto_delete',
        'binding_keys',
        'binding_arguments',
        'arguments',
    ];

    public bool $passive;

    public bool $durable;

    public bool $exclusive;

    public bool $autoDelete;

    /** @var array<string> */
    public array $bindingKeys;

    /** @var array<string, mixed> */
    public array $bindingArguments;

    /** @var array<string, mixed> */
    public array $arguments;

    /**
     * @param array<string>        $bindingKeys
     * @param array<string, mixed> $bindingArguments
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        bool|null $passive = null,
        bool|null $durable = null,
        bool|null $exclusive = null,
        bool|null $autoDelete = null,
        array|null $bindingKeys = null,
        array|null $bindingArguments = null,
        array|null $arguments = null,
    ) {
        $this->passive          = $passive ?? false;
        $this->durable          = $durable ?? true;
        $this->exclusive        = $exclusive ?? false;
        $this->autoDelete       = $autoDelete ?? false;
        $this->bindingKeys      = $bindingKeys ?? [];
        $this->bindingArguments = $bindingArguments ?? [];
        $this->arguments        = $arguments ?? [];
    }

    /**
     * @param array{
     *     passive?: bool|mixed,
     *     durable?: bool|mixed,
     *     exclusive?: bool|mixed,
     *     auto_delete?: bool|mixed,
     *     binding_keys?: array<string>,
     *     binding_arguments?: array<string, mixed>,
     *     arguments?: array<string, mixed>,
     * } $queueConfig
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $queueConfig): self
    {
        self::validate($queueConfig);

        return new self(
            passive: isset($queueConfig['passive']) ? (bool) $queueConfig['passive'] : null,
            durable: isset($queueConfig['durable']) ? (bool) $queueConfig['durable'] : null,
            exclusive: isset($queueConfig['exclusive']) ? (bool) $queueConfig['exclusive'] : null,
            autoDelete: isset($queueConfig['auto_delete']) ? (bool) $queueConfig['auto_delete'] : null,
            bindingKeys: $queueConfig['binding_keys'] ?? null,
            bindingArguments: $queueConfig['binding_arguments'] ?? null,
            arguments: $queueConfig['arguments'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $queueConfig
     *
     * @throws InvalidArgumentException
     */
    public static function validate(array $queueConfig): void
    {
        if (0 < count($invalidQueueOptions = array_diff(array_keys($queueConfig), self::AVAILABLE_OPTIONS))) {
            throw new InvalidArgumentException(sprintf('Invalid queue option(s) "%s" passed to the AMQP Messenger transport.', implode('", "', $invalidQueueOptions)));
        }
    }
}

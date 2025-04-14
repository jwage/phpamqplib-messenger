<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;

use function array_diff;
use function array_keys;
use function count;
use function implode;
use function sprintf;

readonly class BindingConfig
{
    private const array AVAILABLE_OPTIONS = [
        'routing_key',
        'arguments',
    ];

    public string $routingKey;

    /** @var array<string, mixed> */
    public array $arguments;

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        string|null $routingKey = null,
        array|null $arguments = null,
    ) {
        if ($routingKey === null || $routingKey === '') {
            throw new InvalidArgumentException('Binding routing key is required');
        }

        $this->routingKey = $routingKey;
        $this->arguments  = $arguments ?? [];
    }

    /**
     * @param array{
     *     routing_key?: string,
     *     arguments?: array<string, mixed>,
     * } $bindingConfig
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $bindingConfig): self
    {
        self::validate($bindingConfig);

        return new self(
            routingKey: $bindingConfig['routing_key'] ?? '',
            arguments: $bindingConfig['arguments'] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $bindingConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $bindingConfig): void
    {
        if (0 < count($invalidBindingOptions = array_diff(array_keys($bindingConfig), self::AVAILABLE_OPTIONS))) {
            throw new InvalidArgumentException(sprintf('Invalid binding option(s) "%s" passed to the AMQP Messenger transport.', implode('", "', $invalidBindingOptions)));
        }
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;

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
            routingKey: ConfigHelper::getString($bindingConfig, 'routing_key'),
            arguments: ConfigHelper::getArray($bindingConfig, 'arguments'),
        );
    }

    /**
     * @param array<string, mixed> $bindingConfig
     *
     * @throws InvalidArgumentException
     */
    private static function validate(array $bindingConfig): void
    {
        ConfigHelper::validate('binding', $bindingConfig, self::AVAILABLE_OPTIONS);
    }
}

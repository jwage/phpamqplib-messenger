<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport\Config;

use InvalidArgumentException;

use function array_diff;
use function array_keys;
use function assert;
use function count;
use function filter_var;
use function gettype;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function sprintf;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOL;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;

class ConfigHelper
{
    private const string TYPE_STRING  = 'string';
    private const string TYPE_ARRAY   = 'array';
    private const string TYPE_INTEGER = 'integer';
    private const string TYPE_FLOAT   = 'float';
    private const string TYPE_BOOLEAN = 'boolean';

    private const array TYPE_FILTER_MAP = [
        self::TYPE_INTEGER => FILTER_VALIDATE_INT,
        self::TYPE_FLOAT => FILTER_VALIDATE_FLOAT,
        self::TYPE_BOOLEAN => FILTER_VALIDATE_BOOL,
    ];

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    public static function getString(array $config, string $key): string|null
    {
        $filteredValue = self::getType($config, $key, self::TYPE_STRING);
        assert(is_string($filteredValue) || $filteredValue === null);

        return $filteredValue;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidArgumentException
     */
    public static function getArray(array $config, string $key): array|null
    {
        /** @var array<string, mixed>|null $filteredValue */
        $filteredValue = self::getType($config, $key, self::TYPE_ARRAY);
        assert(is_array($filteredValue) || $filteredValue === null);

        return $filteredValue;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    public static function getInt(array $config, string $key): int|null
    {
        $filteredValue = self::getType($config, $key, self::TYPE_INTEGER);
        assert(is_int($filteredValue) || $filteredValue === null);

        return $filteredValue;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    public static function getFloat(array $config, string $key): float|null
    {
        $filteredValue = self::getType($config, $key, self::TYPE_FLOAT);
        assert(is_float($filteredValue) || $filteredValue === null);

        return $filteredValue;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    public static function getBool(array $config, string $key): bool|null
    {
        $filteredValue = self::getType($config, $key, self::TYPE_BOOLEAN);
        assert(is_bool($filteredValue) || $filteredValue === null);

        return $filteredValue;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string>        $availableOptions
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $type, array $config, array $availableOptions): void
    {
        if (0 < count($invalidOptions = array_diff(array_keys($config), $availableOptions))) {
            throw new InvalidArgumentException(sprintf(
                'Invalid %s option(s) "%s" passed to the AMQP Messenger transport - known options: "%s".',
                $type,
                implode('", "', $invalidOptions),
                implode('", "', $availableOptions),
            ));
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    private static function getType(array $config, string $key, string $type): mixed
    {
        $value = $config[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if ($type === self::TYPE_STRING) {
            if (! is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('Invalid type "%s" for key "%s" (expected string)', gettype($value), $key));
            }

            return (string) $value;
        }

        if ($type === self::TYPE_ARRAY) {
            if (! is_array($value)) {
                throw new InvalidArgumentException(sprintf('Invalid type "%s" for key "%s" (expected array)', gettype($value), $key));
            }

            return $value;
        }

        /** @var mixed $filteredValue */
        $filteredValue = filter_var($value, self::TYPE_FILTER_MAP[$type], ['flags' => FILTER_NULL_ON_FAILURE]);

        if ($filteredValue === null) {
            throw new InvalidArgumentException(sprintf('Invalid type "%s" for key "%s" (expected %s)', gettype($value), $key, $type));
        }

        return $filteredValue;
    }
}

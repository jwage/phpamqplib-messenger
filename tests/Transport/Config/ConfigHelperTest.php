<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Transport\Config;

use InvalidArgumentException;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Config\ConfigHelper;
use PHPUnit\Framework\TestCase;
use stdClass;

class ConfigHelperTest extends TestCase
{
    public function testGetString(): void
    {
        self::assertSame('1', ConfigHelper::getString(['string_key' => 1], 'string_key'));
        self::assertSame('string', ConfigHelper::getString(['string_key' => 'string'], 'string_key'));
        self::assertNull(ConfigHelper::getString([], 'string_key'));
    }

    public function testGetStringWithInvalidValue(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "object" for key "string_key" (expected string)');

        ConfigHelper::getString(['string_key' => new stdClass()], 'string_key');
    }

    public function testGetArray(): void
    {
        self::assertSame([1, 2, 3], ConfigHelper::getArray(['array_key' => [1, 2, 3]], 'array_key'));
        self::assertNull(ConfigHelper::getArray([], 'array_key'));
    }

    public function testGetArrayWithInvalidValue(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "string" for key "array_key" (expected array)');

        ConfigHelper::getArray(['array_key' => 'not_an_array'], 'array_key');
    }

    public function testGetInt(): void
    {
        self::assertSame(1, ConfigHelper::getInt(['int_key' => 1], 'int_key'));
        self::assertSame(1, ConfigHelper::getInt(['int_key' => '1'], 'int_key'));
        self::assertNull(ConfigHelper::getInt([], 'int_key'));
    }

    public function testGetIntWithInvalidValue(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "string" for key "int_key" (expected integer)');

        ConfigHelper::getInt(['int_key' => 'not_an_int'], 'int_key');
    }

    public function testGetFloat(): void
    {
        self::assertSame(1.0, ConfigHelper::getFloat(['float_key' => 1], 'float_key'));
        self::assertSame(1.0, ConfigHelper::getFloat(['float_key' => '1'], 'float_key'));
        self::assertSame(1.0, ConfigHelper::getFloat(['float_key' => 1.0], 'float_key'));
        self::assertSame(1.0, ConfigHelper::getFloat(['float_key' => '1.0'], 'float_key'));
        self::assertNull(ConfigHelper::getFloat([], 'float_key'));
    }

    public function testGetFloatWithInvalidValue(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "string" for key "float_key" (expected float)');

        ConfigHelper::getFloat(['float_key' => 'not_a_float'], 'float_key');
    }

    public function testGetBool(): void
    {
        self::assertTrue(ConfigHelper::getBool(['bool_key' => true], 'bool_key'));
        self::assertTrue(ConfigHelper::getBool(['bool_key' => 'true'], 'bool_key'));
        self::assertFalse(ConfigHelper::getBool(['bool_key' => false], 'bool_key'));
        self::assertFalse(ConfigHelper::getBool(['bool_key' => 'false'], 'bool_key'));
        self::assertTrue(ConfigHelper::getBool(['bool_key' => 1], 'bool_key'));
        self::assertFalse(ConfigHelper::getBool(['bool_key' => 0], 'bool_key'));
        self::assertTrue(ConfigHelper::getBool(['bool_key' => '1'], 'bool_key'));
        self::assertFalse(ConfigHelper::getBool(['bool_key' => '0'], 'bool_key'));
        self::assertNull(ConfigHelper::getBool([], 'bool_key'));
    }

    public function testGetBoolWithInvalidValue(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid type "string" for key "bool_key" (expected boolean)');

        ConfigHelper::getBool(['bool_key' => 'not_a_bool'], 'bool_key');
    }

    public function testValidate(): void
    {
        ConfigHelper::validate('connection', ['test' => 'value'], ['test']);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid connection option(s) "invalid" passed to the AMQP Messenger transport - known options: "test".');

        ConfigHelper::validate('connection', ['invalid' => 'value'], ['test']);
    }
}

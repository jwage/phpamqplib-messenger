<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests;

use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\TestCase as BaseTestCase;

use function array_column;
use function count;

class TestCase extends BaseTestCase
{
    /**
     * @param array<mixed> $firstCallArguments
     * @param array<mixed> ...$consecutiveCallsArguments
     *
     * @return iterable<int|string, Callback<mixed>>
     */
    public static function withConsecutive(array $firstCallArguments, array ...$consecutiveCallsArguments): iterable
    {
        $allConsecutiveCallsArguments = [$firstCallArguments, ...$consecutiveCallsArguments];

        $numberOfArguments = count($firstCallArguments);

        /** @var array<int, array<int, mixed>> $argumentList */
        $argumentList = [];

        for ($argumentPosition = 0; $argumentPosition < $numberOfArguments; $argumentPosition++) {
            $argumentList[$argumentPosition] = array_column($allConsecutiveCallsArguments, $argumentPosition);
        }

        $mockedMethodCall = 0;
        $callbackCall     = 0;

        foreach ($argumentList as $index => $_argument) {
            yield $index => new Callback(
                static function (mixed $actualArgument) use ($argumentList, &$mockedMethodCall, &$callbackCall, $index, $numberOfArguments): bool {
                    /** @var mixed $expected */
                    $expected = $argumentList[$index][$mockedMethodCall] ?? null;

                    $callbackCall++;
                    $mockedMethodCall = (int) ($callbackCall / $numberOfArguments);

                    if ($expected instanceof Constraint) {
                        self::assertThat($actualArgument, $expected);
                    } else {
                        self::assertEquals($expected, $actualArgument);
                    }

                    return true;
                },
            );
        }
    }
}

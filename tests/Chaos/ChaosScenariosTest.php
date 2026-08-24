<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

use function is_callable;

#[Group('chaos')]
class ChaosScenariosTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: class-string}> */
    public static function scenarioProvider(): iterable
    {
        foreach (Scenarios::all() as $name => $class) {
            yield $name => [$name, $class];
        }
    }

    /** @param class-string $class */
    #[DataProvider('scenarioProvider')]
    public function testScenario(string $name, string $class): void
    {
        $harness = new Harness();

        try {
            $harness->waitUntilReady();
            $run = [$class, 'run'];
            self::assertTrue(is_callable($run), $name . ' must provide run(Harness)');
            $run($harness);
        } catch (Throwable $exception) {
            self::fail($name . ': ' . $exception->getMessage());
        } finally {
            $harness->cleanup();
        }
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use PhpAmqpLib\Channel\AMQPChannel;
use ReflectionProperty;
use RuntimeException;
use Throwable;

use function array_reverse;
use function assert;
use function count;
use function dirname;
use function escapeshellarg;
use function fclose;
use function fwrite;
use function get_debug_type;
use function getenv;
use function getmypid;
use function hrtime;
use function is_array;
use function is_numeric;
use function microtime;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function uniqid;
use function usleep;

use const STDERR;
use const STDOUT;

final class Harness
{
    private ConnectionFactory $connectionFactory;

    /** @var list<Connection> */
    private array $connections = [];

    /** @var list<resource> */
    private array $backgroundProcesses = [];

    private bool $brokerPaused = false;

    private bool $memoryAlarmed = false;

    public function __construct(
        private CollectingLogger $logger = new CollectingLogger(),
    ) {
        $this->connectionFactory = new ConnectionFactory(
            new DsnParser(),
            new RetryFactory($this->logger),
            new AmqpConnectionFactory(),
            $this->logger,
        );
    }

    public function logger(): CollectingLogger
    {
        return $this->logger;
    }

    public function dsn(): string
    {
        $dsn = getenv('MESSENGER_TRANSPORT_PHPAMQPLIB_DSN');

        if ($dsn === false || $dsn === '') {
            return 'phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages';
        }

        return $dsn;
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @throws RuntimeException
     */
    public function connect(array $options = []): Connection
    {
        $connection          = $this->connectionFactory->fromDsn($this->dsn(), $options);
        $this->connections[] = $connection;

        return $connection;
    }

    public function topologyName(string $scenario): string
    {
        $pid = getmypid();

        return sprintf('chaos_%s_%s_%s', $scenario, $pid === false ? '0' : (string) $pid, uniqid());
    }

    /**
     * @param array<string, mixed> $queue
     * @param array<string, mixed> $extra
     *
     * @return array<array-key, mixed>
     */
    public function topology(string $name, array $queue = [], array $extra = []): array
    {
        return $extra + [
            'auto_setup' => true,
            'confirm_enabled' => true,
            'exchange' => ['name' => $name],
            'queues' => [$name => $queue],
        ];
    }

    public function info(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    public function fail(string $message): never
    {
        throw new RuntimeException($message);
    }

    public function assertTrue(mixed $value, string $message): void
    {
        if ($value) {
            return;
        }

        $this->fail($message);
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected === $actual) {
            return;
        }

        $this->fail(sprintf('%s (expected %s, got %s)', $message, $this->describe($expected), $this->describe($actual)));
    }

    public function assertInstanceOf(string $class, mixed $value, string $message): void
    {
        if ($value instanceof $class) {
            return;
        }

        $this->fail($message);
    }

    public function pendingBatchSize(Connection $connection): int
    {
        $property      = new ReflectionProperty(Connection::class, 'batchMessages');
        $batchMessages = $property->getValue($connection);

        assert(is_array($batchMessages));

        return count($batchMessages);
    }

    public function pendingConfirmChannel(Connection $connection): AMQPChannel|null
    {
        $property = new ReflectionProperty(Connection::class, 'pendingBatchConfirmChannel');
        $channel  = $property->getValue($connection);

        assert($channel instanceof AMQPChannel || $channel === null);

        return $channel;
    }

    /**
     * @param positive-int|0 $retries
     * @param positive-int|0 $waitTime
     */
    public function withRetryDefaults(int $retries, int $waitTime, callable $run): mixed
    {
        $previousRetries  = Retry::$defaultRetries;
        $previousWaitTime = Retry::$defaultWaitTime;
        $previousJitter   = Retry::$defaultJitter;

        Retry::$defaultRetries  = $retries;
        Retry::$defaultWaitTime = $waitTime;
        Retry::$defaultJitter   = false;

        try {
            return $run();
        } finally {
            Retry::$defaultRetries  = $previousRetries;
            Retry::$defaultWaitTime = $previousWaitTime;
            Retry::$defaultJitter   = $previousJitter;
        }
    }

    public function milliseconds(callable $run): float
    {
        $started = hrtime(true);
        $run();

        return (hrtime(true) - $started) / 1_000_000;
    }

    public function broker(string $action): void
    {
        $script = dirname(__DIR__, 2) . '/tests/bin/chaos-broker';
        $this->runCommand($script . ' ' . escapeshellarg($action));

        if ($action === 'pause') {
            $this->brokerPaused = true;
        }

        if ($action === 'unpause') {
            $this->brokerPaused = false;
        }

        if ($action === 'memory-alarm') {
            $this->memoryAlarmed = true;
        }

        if ($action === 'memory-ok') {
            $this->memoryAlarmed = false;
        }
    }

    public function brokerLater(float $seconds, string $action): void
    {
        $script  = dirname(__DIR__, 2) . '/tests/bin/chaos-broker';
        $command = sprintf(
            'sleep %F && %s %s',
            $seconds,
            escapeshellarg($script),
            escapeshellarg($action),
        );

        $process = proc_open(
            ['bash', '-c', $command],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
        );

        if ($process === false) {
            $this->fail('Failed to start background broker command: ' . $action);
        }

        if ($action === 'pause') {
            $this->brokerPaused = true;
        }
    }

    public function waitUntilReady(float $timeoutSeconds = 30): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            try {
                $name       = $this->topologyName('wait');
                $connection = $this->connectionFactory->fromDsn($this->dsn(), $this->topology($name));
                $connection->setup();
                $channel = $connection->channel();
                $channel->queue_delete($name);
                $channel->exchange_delete($name);
                $connection->close();

                return;
            } catch (Throwable) {
                usleep(200_000);
            }
        }

        $this->fail(sprintf('Broker was not reachable via %s', $this->dsn()));
    }

    public function cleanup(): void
    {
        foreach ($this->backgroundProcesses as $process) {
            proc_close($process);
        }

        $this->backgroundProcesses = [];

        if ($this->brokerPaused) {
            try {
                $this->broker('unpause');
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Failed to unpause broker during cleanup: ' . $exception->getMessage() . "\n");
            }
        }

        if ($this->memoryAlarmed) {
            try {
                $this->broker('memory-ok');
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Failed to reset memory alarm during cleanup: ' . $exception->getMessage() . "\n");
            }
        }

        foreach (array_reverse($this->connections) as $connection) {
            try {
                $channel  = $connection->channel();
                $exchange = $connection->getConfig()->exchange->name;

                foreach ($connection->getQueueNames() as $queueName) {
                    $channel->queue_delete($queueName);
                }

                if ($exchange !== '') {
                    $channel->exchange_delete($exchange);
                }
            } catch (Throwable) {
            }

            try {
                $connection->close();
            } catch (Throwable) {
            }
        }

        $this->connections = [];
    }

    private function runCommand(string $command): void
    {
        $this->info('$ ' . $command);

        $process = proc_open(
            ['bash', '-c', $command],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if ($process === false) {
            $this->fail('Failed to run: ' . $command);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);

        if ($stdout !== false && $stdout !== '') {
            fwrite(STDOUT, $stdout);
        }

        if ($status !== 0) {
            $this->fail(sprintf(
                "Command failed (%d): %s\n%s",
                $status,
                $command,
                $stderr === false ? '' : $stderr,
            ));
        }
    }

    private function describe(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}

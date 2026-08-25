<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Retry;
use Jwage\PhpAmqpLibMessengerBundle\RetryFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpEnvelope;
use Jwage\PhpAmqpLibMessengerBundle\Transport\Connection;
use Jwage\PhpAmqpLibMessengerBundle\Transport\ConnectionFactory;
use Jwage\PhpAmqpLibMessengerBundle\Transport\DsnParser;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
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
use function getenv;
use function getmypid;
use function hrtime;
use function is_array;
use function microtime;
use function posix_kill;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function uniqid;
use function usleep;

use const SIGKILL;
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

    private bool $diskAlarmed = false;

    public function __construct(
        private CollectingLogger $logger = new CollectingLogger(),
        private bool $verbose = false,
    ) {
        $verboseEnv    = getenv('CHAOS_VERBOSE');
        $this->verbose = $verbose || $verboseEnv === '1' || $verboseEnv === 'true';

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

    public function sslDsn(): string
    {
        $dsn = getenv('MESSENGER_TRANSPORT_PHPAMQPLIB_SSL_DSN');

        if ($dsn !== false && $dsn !== '') {
            return $dsn;
        }

        $dsn = preg_replace('#^phpamqplib://#', 'phpamqplibs://', $this->dsn()) ?? $this->dsn();

        if (preg_match('#:\\d+/#', $dsn) === 1) {
            return preg_replace('#:(\\d+)/#', ':5671/', $dsn, 1) ?? $dsn;
        }

        return preg_replace('#@([^/]+)/#', '@$1:5671/', $dsn, 1) ?? $dsn;
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @throws RuntimeException
     */
    public function connect(array $options = [], string|null $dsn = null): Connection
    {
        $connection          = $this->connectionFactory->fromDsn($dsn ?? $this->dsn(), $options);
        $this->connections[] = $connection;

        return $connection;
    }

    public function topologyName(string $label): string
    {
        $pid = getmypid();

        return sprintf('chaos_%s_%s_%s', $label, $pid === false ? '0' : (string) $pid, uniqid());
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
        if (! $this->verbose) {
            return;
        }

        fwrite(STDOUT, $message . "\n");
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

    public function publisherChannel(Connection $connection): AMQPChannel|null
    {
        $property = new ReflectionProperty(Connection::class, 'channel');
        $channel  = $property->getValue($connection);

        assert($channel instanceof AMQPChannel || $channel === null);

        return $channel;
    }

    public function wrappedAmqpConnection(Connection $connection): AMQPStreamConnection|null
    {
        $property = new ReflectionProperty(Connection::class, 'connection');
        $amqp     = $property->getValue($connection);

        assert($amqp instanceof AMQPStreamConnection || $amqp === null);

        return $amqp;
    }

    public function consumeOnce(Connection $connection, string $queueName): AmqpEnvelope
    {
        foreach ($connection->consume($queueName) as $envelope) {
            return $envelope;
        }

        $this->fail(sprintf('Expected a delivery on %s from a single consume()', $queueName));
    }

    public function amqpChannelCount(Connection $connection): int
    {
        $amqp = $this->wrappedAmqpConnection($connection);
        if ($amqp === null) {
            return 0;
        }

        return count($amqp->channels);
    }

    public function consumeOne(Connection $connection, string $queueName, float $timeoutSeconds = 15): AmqpEnvelope
    {
        $deadline      = microtime(true) + $timeoutSeconds;
        $lastException = null;

        while (microtime(true) < $deadline) {
            try {
                foreach ($connection->consume($queueName) as $envelope) {
                    return $envelope;
                }
            } catch (Throwable $exception) {
                $lastException = $exception;
                $this->info('consume failed: ' . $exception->getMessage());
            }

            usleep(200_000);
        }

        $this->fail(sprintf(
            'Timed out waiting for a delivery on %s%s',
            $queueName,
            $lastException !== null ? ': ' . $lastException->getMessage() : '',
        ));
    }

    public function waitForMessageCount(Connection $connection, int $expected, float $timeoutSeconds = 10): int
    {
        $deadline      = microtime(true) + $timeoutSeconds;
        $last          = 0;
        $lastException = null;

        while (microtime(true) < $deadline) {
            try {
                $last = $connection->countMessagesInQueues();

                if ($last >= $expected) {
                    return $last;
                }
            } catch (Throwable $exception) {
                $lastException = $exception;
            }

            usleep(100_000);
        }

        $this->fail(sprintf(
            'Timed out waiting for %d message(s); last count %d%s',
            $expected,
            $last,
            $lastException !== null ? ': ' . $lastException->getMessage() : '',
        ));
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

        if ($action === 'pause') {
            $this->brokerPaused = true;
        }

        if ($action === 'memory-alarm') {
            $this->memoryAlarmed = true;
        }

        if ($action === 'disk-alarm') {
            $this->diskAlarmed = true;
        }

        $this->runCommand($script . ' ' . escapeshellarg($action));

        if ($action === 'unpause') {
            $this->brokerPaused = false;
        }

        if ($action === 'memory-ok') {
            $this->memoryAlarmed = false;
        }

        if ($action === 'disk-ok') {
            $this->diskAlarmed = false;
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
            cwd: null,
            env_vars: null,
            options: ['create_new_session' => true],
        );

        if ($process === false) {
            $this->fail('Failed to start background broker command: ' . $action);
        }

        $this->backgroundProcesses[] = $process;

        if ($action === 'pause') {
            $this->brokerPaused = true;
        }

        if ($action === 'disk-alarm') {
            $this->diskAlarmed = true;
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
            $status = proc_get_status($process);
            $pid    = $status['pid'] ?? 0;

            if ($status['running'] === true && $pid > 0) {
                posix_kill(-$pid, SIGKILL);
            }

            proc_close($process);
        }

        $this->backgroundProcesses = [];

        if ($this->brokerPaused) {
            try {
                $this->broker('unpause');
            } catch (Throwable $exception) {
                $this->info('Failed to unpause broker during cleanup: ' . $exception->getMessage());
            }
        }

        if ($this->memoryAlarmed) {
            try {
                $this->broker('memory-ok');
            } catch (Throwable $exception) {
                $this->info('Failed to reset memory alarm during cleanup: ' . $exception->getMessage());
            }
        }

        if ($this->diskAlarmed) {
            try {
                $this->broker('disk-ok');
            } catch (Throwable $exception) {
                $this->info('Failed to reset disk alarm during cleanup: ' . $exception->getMessage());
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

                $delayExchange = $connection->getConfig()->delay->exchange->name;

                if ($connection->getConfig()->delay->enabled && $delayExchange !== '' && $delayExchange !== $exchange) {
                    $channel->exchange_delete($delayExchange);
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

        if ($this->verbose && $stdout !== false && $stdout !== '') {
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

    private function fail(string $message): never
    {
        throw new RuntimeException($message);
    }
}

# PhpAmqpLibMessengerBundle Documentation

This bundle adds support for `php-amqplib/php-amqplib` to Symfony Messenger, providing an alternative way to connect to RabbitMQ using a pure PHP library instead of the [php-amqp](https://github.com/php-amqp/php-amqp) C extension.

It is also compatible with [LavinMQ](https://lavinmq.com/), which speaks the same AMQP 0-9-1 protocol. CI and the local test broker run against RabbitMQ.

## Requirements

- PHP 8.3, 8.4, or 8.5
- Symfony Messenger 6.3, 6.4, 7, or 8

## Installation

```bash
composer require jwage/phpamqplib-messenger
```

Make sure the bundle is enabled in `config/bundles.php`:

```php
return [
    // ...
    Jwage\PhpAmqpLibMessengerBundle\PhpAmqpLibMessengerBundle::class => ['all' => true],
];
```

## DSN Format

It is easy to configure the bundle using a DSN and the `config/packages/messenger.yaml` file. The DSN format for the transport is:

```
phpamqplib://username:password@localhost[:port]/vhost[/exchange]
```

For SSL/TLS connections, use:

```
phpamqplibs://username:password@localhost[:port]/vhost[/exchange]
```

## Fetch size and multiple transports

This transport honors Symfony Messenger's fetch-size hint on `ReceiverInterface::get()`. That is how many envelopes one Worker `get()` yields before returning; it is not AMQP QoS (`prefetch_count`).

On **Symfony 8.1+**, the Worker always passes a size via `messenger:consume --fetch-size` (default 1). Use that option. Transport `fetch_size` is ignored, because the argument is always present.

On **Symfony < 8.1**, the Worker calls `get()` with no argument. `wait_timeout` only fires when the queue is idle, so a busy transport can yield forever and starve other receivers, `--time-limit`, and `messenger:stop-workers`. Set transport `fetch_size` as the default for that omitted argument:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            orders:
                dsn: 'phpamqplib://guest:guest@localhost:5672/%2f/orders'
                options:
                    fetch_size: 10
```

That yaml value is the same cap as `--fetch-size`, supplied only when the caller does not pass `$fetchSize`. It is not a yaml equivalent of the console option.

The Worker still prefers the first transport listed whenever that transport has work. For isolated throughput, consume each transport in its own process:

```bash
bin/console messenger:consume transport1
bin/console messenger:consume transport2
```

A single `messenger:consume` process can still listen to several phpamqplib transports, including alongside Doctrine, Redis, or other receivers.

When every receiver is phpamqplib, the first `get()` of a worker pass waits on all of those sockets at once. That wait is at least `messenger:consume --sleep` and not shorter than the shortest `wait_timeout` on that connection (connection-level or per-queue), so leftover `--sleep` does not sit between idle and the next message with no socket selected. Later transports in the same pass only drain. A delivery is therefore handled in that same iteration. SIGINT and idle checks stay close to one wait rather than scaling with the number of phpamqplib transports. If that wait returns early because the socket died, `get()` reconnects before returning empty so Worker leftover `--sleep` does not delay Retry. If there is no socket yet (the broker is down), the wait still lasts `wait_timeout`, so `--sleep=0` cannot busy-loop start-failure warnings.

When the same worker also consumes a non-phpamqplib transport, `get()` only drains frames that already arrived. After every receiver has been checked and the worker is idle, it waits on all phpamqplib sockets for `messenger:consume --sleep` (or until the worker deadline). That keeps AMQP sockets selected for the whole idle interval so leftover `--sleep` does not run with no socket selected, and the other transports keep being polled on that same interval. When `--sleep` is 0, that idle wait uses `wait_timeout` instead so the process does not busy-poll. Direct `get()` calls outside `messenger:consume` still wait per queue, which is what tests and custom consumers do.

A single transport can declare multiple queues. The receiver subscribes to each queue rather than only the first. Messages that arrive for another already-subscribed queue during a wait are buffered and returned when that queue is polled. When a fetch size is in effect (CLI argument or transport default), the receiver stops after that many envelopes across those queues.

## Minimum Configuration

The minimum configuration requires a transport name and DSN.

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            orders:
                dsn: 'phpamqplib://guest:guest@localhost:5672/myvhost/orders'
```

The configuration above will create an exchange named `orders` and bind a queue named `orders` to it within the vhost `myvhost`.

## Advanced Configuration

The bundle supports advanced RabbitMQ configuration options in your `config/packages/messenger.yaml` file. The following comprehensive example shows the available options; review application-specific values such as names, timeouts, heartbeat, and certificate settings for your environment:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            orders:
                dsn: 'phpamqplib://localhost:5672/%2f'
                options:
                    # Automatically setup the exchange and queues
                    # If disabled, you must manually setup the exchange and queues with messenger:setup-transports
                    auto_setup: true

                    # Connection options
                    host: 'localhost'
                    port: 5672
                    user: 'guest'
                    # Optional for compatibility with symfony/amqp-messenger
                    # login: 'guest'
                    password: 'guest'
                    vhost: '/'
                    insist: false
                    login_method: 'AMQPLAIN'
                    locale: 'en_US'

                    # Timeout settings
                    connect_timeout: 3.0
                    read_timeout: 3.0
                    write_timeout: 3.0
                    rpc_timeout: 3.0

                    # Heartbeat settings
                    heartbeat: 0
                    keepalive: true

                    # Send AMQP heartbeats while a message handler is running (opt-in).
                    # Off by default: enabling TCP keepalive (`keepalive`) or passing
                    # `messenger:consume --keepalive` does not turn this on.
                    #
                    # Set to true when handlers can run longer than the AMQP heartbeat
                    # interval. This only sends frames when `heartbeat` is greater than 0.
                    # Also requires Symfony >= 7.2, the pcntl extension, and `--keepalive`
                    # on consume so the worker can call the transport during processing.
                    keepalive_enabled: false

                    # Prefetch settings (AMQP QoS: max unacked deliveries on the channel)
                    prefetch_count: 1

                    # Default for get() when the caller omits $fetchSize (Symfony < 8.1
                    # Worker). Same hint as messenger:consume --fetch-size. Omit for
                    # unlimited. Ignored when get($fetchSize) is passed, including
                    # Symfony 8.1+ consume which always passes --fetch-size.
                    fetch_size: 10

                    # Consume wait settings
                    wait_timeout: 1

                    # Confirm settings
                    confirm_enabled: true
                    confirm_timeout: 3.0

                    # Transactions are an alternative to publisher confirms;
                    # confirm_enabled and transactions_enabled cannot both be true.
                    transactions_enabled: false

                    # Retry retryable connection and channel failures (closed
                    # connection, I/O errors, timeouts). Enabled by default so a
                    # publish is less likely to be lost. Recovery can deliver a
                    # message more than once, so handlers must be idempotent.
                    # Disable only if you cannot make handlers idempotent and
                    # accept possible loss when a connection fails during publish.
                    retries_enabled: true
                    # Retry attempts after the first failure. Defaults to 3.
                    retries: 3
                    # Milliseconds to wait between retry attempts (jittered
                    # between 0 and this value). Defaults to 1000. Three retries
                    # therefore stall at most about 3 seconds before failing.
                    retry_wait_time: 1000

                    # Connection name (optional for easier identification in server logs and management UI)
                    connection_name: ''

                    # SSL/TLS configuration
                    ssl:
                        cafile: '/path/to/ca_certificate.pem'
                        capath: '/path/to/ca_certificate_path'
                        local_cert: '/path/to/local_certificate.pem'
                        local_pk: '/path/to/local_private_key.pem'
                        verify_peer: true
                        verify_peer_name: true
                        passphrase: 'passphrase'
                        ciphers: 'TLS_AES_256_GCM_SHA384'
                        security_level: 2
                        crypto_method: !php/const:STREAM_CRYPTO_METHOD_ANY_CLIENT

                    # Exchange configuration
                    exchange:
                        name: 'orders_exchange'
                        type: 'fanout'
                        default_publish_routing_key: ''
                        passive: false
                        durable: true
                        auto_delete: false
                        arguments: []

                    # Queue configuration
                    queues:
                        orders_messages:
                            prefetch_count: 5 # overrides the connection prefetch_count: 1
                            wait_timeout: 2.0 # overrides the connection wait_timeout: 1.0
                            passive: false
                            durable: true
                            exclusive: false
                            auto_delete: false
                            # Optional "binding_keys" for compatibility with symfony/amqp-messenger
                            # binding_keys: ['routing_key1', 'routing_key2']
                            bindings:
                                routing_key1:
                                    arguments: []
                                routing_key2:
                                    arguments: []
                            arguments: []

                    # Delay configuration
                    delay:
                        exchange:
                            name: 'delays'
                            type: 'direct'
                            default_publish_routing_key: ''
                            passive: false
                            durable: true
                            auto_delete: false
                            arguments: []
                        enabled: true
                        auto_setup: true
                        queue_name_pattern: 'delay_%exchange_name%_%routing_key%_%delay%'
                        # Declare the delay/retry queues as durable. Defaults to false.
                        # RabbitMQ 4.3+ denies the deprecated `transient_nonexcl_queues`
                        # feature by default, which makes the transient delay queue
                        # declaration fail. Set this to true when running against such a
                        # broker. The delay queues are still cleaned up via `x-expires`.
                        durable: false
                        arguments: []
```

Any option can be specified in the DSN as an alternative to defining it in the `messenger.yaml` file:

```
phpamqplib://guest:guest@localhost?heartbeat=60&read_timeout=5.0
phpamqplib://guest:guest@localhost?heartbeat=10&keepalive_enabled=true
phpamqplib://guest:guest@localhost?fetch_size=10
phpamqplib://guest:guest@localhost?retries_enabled=false
phpamqplib://guest:guest@localhost?retries=2&retry_wait_time=500
```

`fetch_size` on the DSN is the transport default described above, not `messenger:consume --fetch-size`.

`keepalive_enabled=true` has no effect unless `heartbeat` is greater than 0. It also requires Symfony >= 7.2, the `pcntl` extension, and `messenger:consume --keepalive`.

`retries_enabled=false` disables transport retries of retryable connection and channel failures. See [Delivery Reliability](#delivery-reliability).

When retries are enabled, the transport retries up to `retries` times (default 3) and waits up to `retry_wait_time` milliseconds between attempts (default 1000, jittered). That bounds a failed operation to about 3 seconds before the exception is thrown. Raise these only when you need a longer recovery window, such as waiting out a broker restart.

## AmqpStamp

This bundle offers an `AmqpStamp` that is mostly compatible with the `symfony/amqp-messenger` bundle. You can use it to set the routing key and other message attributes when dispatching a message.

The constructor is slightly different from the `symfony/amqp-messenger` bundle. This is what ours looks like:

```php
public function __construct(
    private string|null $routingKey = null,
    private array $attributes = [],
) {
}
```

Versus the `symfony/amqp-messenger` bundle:

```php
public function __construct(
    private ?string $routingKey = null,
    private int $flags = \AMQP_NOPARAM,
    private array $attributes = [],
) {
}
```
Here is how you can use it:

```php
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;

$stamp = new AmqpStamp(routingKey: 'routing_key');

$envelope = Envelope::wrap($message)->with($stamp);

$bus->dispatch($envelope);
```

## Delivery Reliability

The transport implements **at-least-once**, not exactly-once, publishing semantics. Publisher confirms are enabled by default, messages are persistent, and exchanges and queues are durable by default. After a retryable connection or channel failure, the transport either retries messages it still owns or throws so the caller can retry. If RabbitMQ accepted a publish before the connection failed, recovery may publish that message twice, so handlers must be idempotent.

Retries are enabled by default (`retries_enabled: true`) with 3 retries and a jittered wait of up to 1000ms between attempts. Disable them only if you cannot make handlers idempotent and accept that a connection failure during publish may lose the message. Raise `retries` or `retry_wait_time` when a longer recovery window is required.

Publisher confirms and transactions are mutually exclusive. If both are disabled, a successful socket write cannot prove durable broker acceptance. End-to-end durability also depends on keeping messages persistent, topology durable, and RabbitMQ configured for the durability guarantees your application requires.

## Batch Dispatching

The bundle supports batch dispatching through `Jwage\PhpAmqpLibMessengerBundle\Batch`. Create a batch around your existing message bus and choose the number of messages that triggers an automatic flush:

```php
use Jwage\PhpAmqpLibMessengerBundle\Batch;
use Symfony\Component\Messenger\MessageBusInterface;

class SomeService
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function someMethod(): void
    {
        $iterable = ...;

        $batch = Batch::new($this->bus, 10);

        foreach ($iterable as $message) {
            $batch->dispatch($message);
        }

        $batch->flush();
    }
}
```

Batched `flush()` is **at-least-once**, not exactly-once. The transport owns messages accepted for a batch until a flush succeeds, including across reconnect and `Connection::close()`. A failed flush retains the batch and throws; reaching or exceeding the batch size on a later dispatch attempts the flush again.

A live publisher-confirm timeout retries the wait on the original channel and does not call `publish_batch()` again. If those waits are exhausted, the batch remains pending and `flush()` throws. If the connection or channel dies after the write or while waiting for confirms, recovery replays the retained batch because RabbitMQ's outcome is unknowable. That replay may produce duplicates, so handlers must be idempotent (or use the [deduplication plugin](#deduplication-plugin)).

A later non-batch publish on the same connection flushes any retained batch first, so a newer direct message cannot overtake older buffered messages. If that flush still fails, the direct publish throws without sending the newer message.

Pending batches exist only in process memory. They do not survive process termination, so call `flush()` explicitly and allow its exception to propagate; do not rely on destructor-time flushing for durability.

## Publisher and Consumer Channels

Publishing/topology operations and consuming use separate AMQP channels, even when they share one underlying connection. A live publisher-channel failure can therefore be retired and replaced without invalidating an in-flight consumer delivery tag. If the underlying connection dies, its delivery tags are no longer valid and the transport re-registers its consumers on a fresh channel. `Connection::channel()` returns the publisher/topology channel; use the transport receiver or `Connection::consume()` for consumption.

When RabbitMQ reports a resource alarm, known-blocked publishes fail before allocating another channel. A publisher channel that discovers the alarm is reclaimed after the connection becomes readable again and before its replacement is opened.

## Deduplication Plugin

At-least-once recovery can publish a message more than once when RabbitMQ accepted it but the client lost the connection before learning the outcome. Idempotent handlers remain the primary defense against duplicate processing.

The optional [RabbitMQ message deduplication plugin](https://github.com/noxdafox/rabbitmq-message-deduplication) can suppress publishes that reuse the same message ID while they remain within its configured cache. This complements rather than replaces idempotent handlers.

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async:
                dsn: 'phpamqplib://guest:guest@localhost:5672/myvhost'
                options:
                    exchange:
                        name: async_exchange
                        type: x-message-deduplication
                        arguments:
                            x-cache-size: 100000
                            x-cache-ttl: 60000
                    queues:
                        async_messages: ~
```

Now when publishing a message, you can set the `message_id` property when dispatching the message:

```php
$messageId = '123'; // generate unique message id

$envelope = Envelope::wrap($message)->with(AmqpStamp::createWithAttributes(
    attributes: [
        'headers' => ['x-deduplication-header' => $messageId],
        'message_id' => $messageId,
    ]
));

$bus->dispatch($envelope);
```

To make this easier, use `DeduplicationPluginMiddleware` to preserve an existing message ID or generate one with `symfony/uid`, then set both the AMQP `message_id` property and `x-deduplication-header`:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - 'Jwage\PhpAmqpLibMessengerBundle\Middleware\DeduplicationPluginMiddleware'
```

## Running Tests

Tests are split by folder into PHPUnit suites. `./vendor/bin/phpunit` runs **unit**, **functional**, and **e2e**. **chaos** is excluded because it pauses and restarts the broker.

| Suite | Path | Broker | Mutates broker |
|---|---|---|---|
| `unit` | `tests/Unit` | no | no |
| `functional` | `tests/Functional` | yes | no |
| `e2e` | `tests/E2e` | yes | no |
| `chaos` | `tests/Chaos` | yes | yes |

```bash
./vendor/bin/phpunit --testsuite unit
# or: composer test:unit

docker compose up -d --wait
./vendor/bin/phpunit --testsuite functional
./vendor/bin/phpunit --testsuite e2e
./vendor/bin/phpunit --testsuite unit,functional,e2e   # same as ./vendor/bin/phpunit
docker compose down
```

Functional and e2e tests need a RabbitMQ broker. Port `5672` is often already taken by another local broker, so this suite defaults to **port 5673**.

```bash
docker compose up -d --wait
./vendor/bin/phpunit
docker compose down
```

Override the DSN if needed:

```bash
MESSENGER_TRANSPORT_PHPAMQPLIB_DSN='phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages' ./vendor/bin/phpunit
```

If the broker is down or unreachable, functional and e2e tests **fail** (they do not skip).

### Wait and consume debug traces

Detailed wait/consume traces follow Symfony's **kernel debug** flag (`APP_DEBUG`). They are off in production even though the application logger still exists. In the `dev` environment they are written at `debug`. Retry and AMQP-exception **warning/info** logs are separate and always follow your logger.

This package's tests keep traces on so wait/get/drain behavior can be inspected under `TEST_LOG_DIR` (defaults to `tests/_output`): `KernelTestCase` boots with debug, e2e `messenger:consume` sets `APP_DEBUG=1`, and chaos constructs `Debug` enabled.

### Live broker failure tests

Broker mutations (restart, pause, overflow NACK, memory alarm, TLS listener) are PHPUnit tests in the **`chaos` testsuite** (`tests/Chaos`). They are excluded from `./vendor/bin/phpunit` so they do not pause the functional-test broker.

```bash
docker compose up -d --wait
./vendor/bin/phpunit --testsuite chaos
# or: composer chaos
```

See [`tests/Chaos/README.md`](../tests/Chaos/README.md) for the test catalog and how tests inject broker faults. CI runs them in a separate job. Do not run them in parallel with unit, functional, or e2e; they pause and restart the broker.

### Mutation testing

[Infection](https://infection.github.io/) mutates `src/` and checks that the **unit** and **functional** suites kill those mutants. It does not run `chaos`, `e2e`, `ConnectionLiveTest`, or `MultiTransportWorkerTest`.

Functional tests need a broker. Infection uses a single thread so mutant processes do not share RabbitMQ topology.

```bash
docker compose up -d --wait
composer infection
```

On pull requests, CI mutates only changed lines (`--git-diff-lines`) and generates coverage from `{Class}Test` (`--map-source-class-to-test`). Pushes to `main` run the full set. Both fail unless every covered mutant is killed (`minCoveredMsi` 100 in `infection.json5.dist`).

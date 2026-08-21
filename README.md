# PhpAmqpLibMessengerBundle

This bundle adds support for `php-amqplib/php-amqplib` to Symfony Messenger, providing an alternative way to connect to RabbitMQ using a pure PHP library instead of the [php-amqp](https://github.com/php-amqp/php-amqp) C extension.

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

## Roadmap

The current goal is to stabilize the public API and delivery semantics for a 1.0 release as a third-party Symfony bundle. Possible upstream integration can be evaluated after that milestone.

## Documentation

For detailed documentation, including advanced configuration options, features, and usage examples, please see the [documentation](docs/index.md).

## Why use this bundle?

There are several reasons why you might prefer to use the `php-amqplib/php-amqplib` library over the `php-amqp` C extension for connecting to RabbitMQ in Symfony Messenger:

1. **Asynchronous Consumers**: The `php-amqplib` library properly implements asynchronous consumers, which allows for more efficient message handling. This is particularly useful for advanced use cases where you need to handle a large number of messages concurrently.

2. **Active Maintenance**: Both `php-amqplib` and `php-amqp` are actively maintained. `php-amqplib` is often preferred for its flexibility and ease of use in PHP applications.

3. **PHP Version Compatibility**: Using `php-amqplib` makes upgrading PHP versions easier, as it does not rely on a C extension that may have compatibility issues with newer PHP versions.

4. **Efficient Message Streaming**: The `php-amqplib` library allows for proper streaming of messages from the server, avoiding the inefficiencies of constant polling with `get()`. This means that you can maintain an open stream connection and control how long to wait for messages, which is not possible with the `php-amqp` extension.

5. **Safe Worker Shutdown**: With `php-amqplib`, you can safely stop your workers using `pcntl` signals, ensuring that your handlers do not get shut down mid-message handling. This is a significant advantage over the `php-amqp` extension, where the `consume()` method does not work as expected, leading to potential issues with worker shutdown.

In summary, `php-amqplib` provides a more robust and flexible solution for connecting to RabbitMQ in Symfony Messenger, making it the preferred choice for many developers.

## Message Reliability

This bundle prioritizes message reliability over raw performance and implements **at-least-once**, not exactly-once, publishing semantics. Publisher confirms are enabled by default. After a retryable connection or channel failure, the transport either retries messages it still owns or throws so the caller can retry; it does not turn an uncertain publish into silent success. If RabbitMQ accepted a publish before the connection failed, recovery may publish that message twice, so handlers must be idempotent.

For batched publishing, a live publisher-confirm timeout re-waits on the same channel instead of republishing. Batches remain in memory until `flush()` succeeds, including across reconnect and `Connection::close()`, but they are not durable across process termination. Always call `flush()` explicitly and propagate failures.

Broker-confirmed durability requires publisher confirms (the default) or transactions, persistent messages, durable topology, and appropriate RabbitMQ durability settings. If confirms and transactions are both disabled, a successful socket write cannot prove that RabbitMQ durably accepted the message.

See the [batch delivery contract](docs/index.md#batch-dispatching) and the optional [deduplication plugin](docs/index.md#deduplication-plugin).

## Acknowledgements

We would like to express our sincere gratitude to [@videlalvaro](https://github.com/videlalvaro), the author of the [php-amqplib](https://github.com/php-amqplib/php-amqplib) library, for his invaluable contributions to the development of this project. We also acknowledge Microsoft for supporting his efforts, as he utilized company time to help make this Symfony bundle a robust and reliable solution for connecting to RabbitMQ in Symfony applications.

## License

This bundle is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

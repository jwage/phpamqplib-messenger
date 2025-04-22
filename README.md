# PhpAmqpLibMessengerBundle

This bundle adds support for `php-amqplib/php-amqplib` to Symfony Messenger, providing an alternative way to connect to RabbitMQ using a pure PHP library instead of the [php-amqp](https://github.com/php-amqp/php-amqp) C extension.

## Installation

```bash
composer require jwage/phpamqplib-messenger
```

Make sure the bundled is enabled in `config/bundles.php`:

```php
return [
    // ...
    Jwage\PhpAmqpLibMessengerBundle\PhpAmqpLibMessengerBundle::class => ['all' => true],
];
```

The minimum configuration required is the transports name and the DSN.

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            orders:
                dsn: 'phpamqplib://guest:guest@localhost:5672/myvhost/orders'
```

The configuration above will create an exchange named `orders` and bind a queue named `orders` to it within the vhost `myvhost`.

## Documentation

For detailed documentation, including advanced configuration options, features, and usage examples, please see the [documentation](docs/index.md).

## Why use this bundle?

There are several reasons why you might prefer to use the `php-amqplib/php-amqplib` library over the `php-amqp` C extension for connecting to RabbitMQ in Symfony Messenger:

1. **Asynchronous Consumers**: The `php-amqplib` library properly implements asynchronous consumers, which allows for more efficient message handling. This is particularly useful for advanced use cases where you need to handle a large number of messages concurrently.

2. **Active Maintenance**: Both `php-amqplib` and `php-amqp` are actively maintained by VMware. However, `php-amqplib` is often preferred for its flexibility and ease of use in PHP applications.

3. **PHP Version Compatibility**: Using `php-amqplib` makes upgrading PHP versions easier, as it does not rely on a C extension that may have compatibility issues with newer PHP versions.

4. **Efficient Message Streaming**: The `php-amqplib` library allows for proper streaming of messages from the server, avoiding the inefficiencies of constant polling with `get()`. This means that you can maintain an open stream connection and control how long to wait for messages, which is not possible with the `php-amqp` extension.

5. **Safe Worker Shutdown**: With `php-amqplib`, you can safely stop your workers using `pcntl` signals, ensuring that your handlers do not get shut down mid-message handling. This is a significant advantage over the `php-amqp` extension, where the `consume()` method does not work as expected, leading to potential issues with worker shutdown.

In summary, `php-amqplib` provides a more robust and flexible solution for connecting to RabbitMQ in Symfony Messenger, making it the preferred choice for many developers.

## Acknowledgements

We would like to express our sincere gratitude to [@videlalvaro](https://github.com/videlalvaro), the author of the [php-amqplib](https://github.com/php-amqplib/php-amqplib) library, for his invaluable contributions to the development of this project. We also acknowledge Microsoft for supporting his efforts, as he utilized company time to help make this Symfony bundle a robust and reliable solution for connecting to RabbitMQ in Symfony applications.

## License

This bundle is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

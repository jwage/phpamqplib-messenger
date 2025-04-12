# PhpAmqpLibMessengerBundle Documentations

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

## Configuration

The bundle supports all standard RabbitMQ configuration options:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            amqp:
                dsn: 'phpamqplib://localhost:5672/%2f'
                options:
                    # Connection options
                    host: 'localhost'
                    port: 5672
                    user: 'guest'
                    password: 'guest'
                    vhost: '/'

                    # SSL/TLS configuration
                    cacert: '/path/to/ca_certificate.pem'

                    # Timeout settings
                    connection_timeout: 3.0
                    read_timeout: 3.0
                    write_timeout: 3.0
                    channel_rpc_timeout: 3.0

                    # Heartbeat settings
                    heartbeat: 60
                    keepalive: true

                    # Exchange configuration
                    exchange:
                        name: 'messages'
                        type: 'direct'
                        durable: true
                        auto_delete: false
                        arguments: []

                    # Queue configuration
                    queues:
                        messages:
                            name: 'messages'
                            durable: true
                            exclusive: false
                            auto_delete: false
                            binding_keys: ['']
                            arguments:
                                x-message-ttl: 10000
                                x-max-length: 1000
                                x-max-priority: 10

                    # Delay configuration
                    delay:
                        exchange_name: 'delayed'
                        queue_name_pattern: 'delay_%exchange_name%_%routing_key%_%delay%'
```

### DSN Format

The DSN format for the transport is:

```
phpamqplib://username:password@localhost[:post]/vhost[/exchange]
```

For SSL/TLS connections, use:

```
phpamqplibs://username:password@localhost[:port]/vhost[/exchange]
```

You can optionally specify any setting on the DSN instead of in the `messenger.yaml` file:

```
phpamqplib://username:password@localhost[:port]/vhost[/exchange]?heartbeat=60&read_timeout=5.0
```

# Queue Name Pattern Placeholders

This document explains how to use placeholders in the `queue_name_pattern` configuration for delay transports.

## Problem Solved

When configuring delay transports with `queue_name_pattern` containing `%delay%`, Symfony's Dependency Injection container would try to resolve `%delay%` as a container parameter during compilation, causing this error:

```
You have requested a non-existent parameter "delay" while loading extension "framework".
```

## Solution: Use Curly Brace Placeholders

To avoid this issue, use curly brace syntax `{placeholder}` instead of percent syntax `%placeholder%` in your configuration. The DelayConfig class will automatically convert these to the correct runtime placeholders.

## Configuration Examples

### Basic Usage

```yaml
# messenger.yaml
framework:
    messenger:
        transports:
            delay:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    # Use {delay} instead of %delay%
                    queue_name_pattern: 'message-bus.delay-queue.{delay}'
```

### Multiple Placeholders

```yaml
framework:
    messenger:
        transports:
            delay:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    queue_name_pattern: 'delay-{exchange_name}-{routing_key}-{delay}'
```

### Mixed with DI Parameters

You can still use regular Symfony DI parameters alongside curly brace placeholders:

```yaml
framework:
    messenger:
        transports:
            delay:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    # %app.queue_prefix% is resolved by DI, {delay} is a runtime placeholder
                    queue_name_pattern: '%app.queue_prefix%.delay-{delay}'
```

## Available Placeholders

- `{delay}` - The delay time in milliseconds
- `{exchange_name}` - The exchange name
- `{routing_key}` - The routing key

## Backward Compatibility

Existing configurations using `%placeholder%` syntax continue to work as before. This change only adds support for the new `{placeholder}` syntax to avoid DI compilation issues.

## How It Works

1. **Configuration**: Use `{placeholder}` in your YAML files
2. **Processing**: DelayConfig automatically converts `{placeholder}` to `%placeholder%` 
3. **Runtime**: The transport uses `%placeholder%` for actual queue name generation

This ensures that Symfony's DI container doesn't try to resolve runtime placeholders as container parameters during compilation.

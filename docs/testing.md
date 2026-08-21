# Running Tests

Functional tests need a RabbitMQ broker. Port `5672` is often already taken by another local broker, so this suite defaults to **port 5673**.

```bash
docker compose up -d --wait
./vendor/bin/phpunit
docker compose down
```

Override the DSN if needed:

```bash
MESSENGER_TRANSPORT_PHPAMQPLIB_DSN='phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages' ./vendor/bin/phpunit
```

If the broker is down or unreachable, functional tests **fail** (they do not skip).

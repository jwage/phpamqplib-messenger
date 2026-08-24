# Live broker chaos scenarios

PHPUnit already covers mocked unit behaviour and in-process functional cases (for example dropping the client TCP socket with reflection). It cannot honestly inject **broker-side** failures: a RabbitMQ restart, a frozen confirm path, a `basic.nack` from overflow, or a memory alarm.

These scripts are for that gap. They talk to the docker compose broker, mutate it, and assert at-least-once publish behaviour. They are **not** part of `vendor/bin/phpunit`. CI runs them in a separate job so they do not pause or restart the PHPUnit broker.

## When to run them

Use these after changing publish, flush, confirm, retry, or channel-retirement code — especially when an agent needs to prove a failure mode that mocks only simulate.

Do not run them at the same time as PHPUnit. They pause and restart the same RabbitMQ instance.

## How to run

```bash
docker compose up -d --wait
# or: tests/bin/chaos-broker up

php tests/bin/chaos.php --list
php tests/bin/chaos.php                  # every scenario
php tests/bin/chaos.php smoke nack-overflow

tests/bin/chaos-broker down
```

Override the DSN if needed:

```bash
MESSENGER_TRANSPORT_PHPAMQPLIB_DSN='phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages' \
  php tests/bin/chaos.php smoke
```

Exit `0` means every selected scenario passed. Exit `1` prints `FAIL <name>: ...`.

## Broker commands

`tests/bin/chaos-broker` is the only supported way to inject broker faults:

| Command | Effect |
|---|---|
| `up` / `down` / `wait` / `status` | Lifecycle |
| `restart` | Kill and bring RabbitMQ back |
| `stop` / `start` | Stop without removing the container |
| `pause` / `unpause` | Freeze the broker so confirms never arrive |
| `memory-alarm` / `memory-ok` | Force / clear `connection.blocked` |

## Scenarios

| Name | What it proves |
|---|---|
| `smoke` | The broker is reachable and a publish enqueues |
| `nack-overflow` | `x-overflow: reject-publish` becomes `PublisherNack` and is **not** retried |
| `confirm-timeout-rewait` | A live confirm timeout keeps the channel and re-waits instead of calling `publish_batch` again |
| `broker-restart-before-flush` | A retained in-memory batch still flushes after restart |
| `broker-restart-during-flush` | An in-flight flush never becomes a silent empty-channel success (at-least-once; duplicates allowed) |
| `memory-alarm` | A blocked connection fails the publish instead of succeeding or opening extra channels |
| `retained-batch-before-direct` | After a failed flush, a later direct publish flushes the old batch first |

## Adding a scenario

1. Add a class under `tests/Chaos/Scenario/` with `DESCRIPTION` and `run(Harness $harness): void`.
2. Register it in `tests/bin/chaos.php`.
3. Use unique topology names from `$harness->topologyName(...)`.
4. Inject faults only through `$harness->broker()` / `$harness->brokerLater()`.
5. Throw via `$harness->fail()` / assertions so the runner can print `FAIL`.

`Harness::cleanup()` unpauses the broker, clears a memory alarm, and deletes the scenario's exchange/queue.

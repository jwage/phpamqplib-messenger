# Live broker chaos scenarios

These are PHPUnit tests in the `chaos` testsuite. They talk to the docker compose broker, mutate it (restart, pause, overflow, memory alarm), and assert at-least-once publish behaviour.

They are **excluded from the default suite** (`./vendor/bin/phpunit`) so they do not pause or restart the broker used by functional tests. CI runs them in a separate job.

## When to run them

Use these after changing publish, flush, confirm, retry, or channel-retirement code — especially when an agent needs to prove a failure mode that mocks only simulate.

Do not run them at the same time as the default PHPUnit suite. They pause and restart the same RabbitMQ instance.

## How to run

```bash
docker compose up -d --wait
# or: tests/bin/chaos-broker up

./vendor/bin/phpunit --testsuite chaos
./vendor/bin/phpunit --testsuite chaos --filter nack-overflow
# wrappers:
php tests/bin/chaos.php --list
php tests/bin/chaos.php nack-overflow
composer chaos

tests/bin/chaos-broker down
```

Override the DSN if needed:

```bash
MESSENGER_TRANSPORT_PHPAMQPLIB_DSN='phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages' \
  ./vendor/bin/phpunit --testsuite chaos
```

TLS tests use port **5671** (`MESSENGER_TRANSPORT_PHPAMQPLIB_SSL_DSN` if you need to override it).

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
| `batch-nack-overflow` | A batch flush NACK keeps the buffer and does not auto-replay into the full queue |
| `confirm-timeout-rewait` | A live batch confirm timeout keeps the channel, re-waits, then a later publish does not duplicate |
| `direct-confirm-timeout-rewait` | A live direct-publish confirm timeout keeps the channel and does not republish |
| `broker-restart-before-flush` | A retained in-memory batch still flushes after restart |
| `broker-restart-during-flush` | An in-flight flush never becomes a silent empty-channel success (at-least-once; duplicates allowed) |
| `direct-publish-after-broker-restart` | A later direct publish recovers after restart |
| `consumer-recovers-after-broker-restart` | A persistent message can be consumed and acked after restart |
| `delay-setup-after-broker-restart` | Delayed publish recreates delay topology after restart |
| `memory-alarm` | A blocked connection fails the publish instead of succeeding or opening extra channels |
| `memory-alarm-flush` | A blocked flush keeps the batch, then publishes it after the alarm clears |
| `retained-batch-before-direct` | After a failed flush, a later direct publish flushes the old batch first |
| `auto-flush-after-failed-flush` | After a failed auto-flush at the threshold, a later publish still flushes the retained batch |
| `consumer-ack-survives-publisher-nack` | A publisher NACK does not invalidate an outstanding consumer delivery tag |
| `heartbeat-stall` | A heartbeat-enabled connection recovers after the broker is paused |
| `ssl-publish` | `phpamqplibs://` publish/consume works against the compose TLS listener |

Live cases that do **not** mutate the broker run in the default suite (`LiveConnectionTest`): confirms-disabled publish/flush, isolated channel close, wrong-password auth, and idle heartbeat.

## Failure coverage

How the important `Connection` / `RetryFactory` failure paths are locked:

| Failure | Unit | Functional (live broker) | Chaos (mutates broker) |
|---|---|---|---|
| Direct publish connection/channel death | `testDirectPublishRetriesWhenTheConnectionCloses` | `testDirectPublishRecoversAfterBrokerSocketIsDropped` | `direct-publish-after-broker-restart` |
| Isolated publisher/consumer channel close | `testFlushRepublishesBatchAfterChannelClosed`, `testConsumerResumesWhenItsChannelClosesOnALiveConnection` | `LiveConnectionTest` channel-close tests | — |
| Direct publisher NACK, including retries remaining | `testDirectPublishDoesNotRetryWhenTheBrokerNacksTheMessage` | — | `nack-overflow` |
| Direct confirm timeout does not republish (retries remaining) | `testDirectPublishDoesNotRepublishWhenPendingAcksTimeOutWhileRetriesRemain` | — | `direct-confirm-timeout-rewait` |
| Batch flush connection/channel death | `testFlushRepublishesBatchAfterConnectionClosed` | `testBatchFlushRecoversAfterBrokerSocketIsDropped` | `broker-restart-before-flush`, `broker-restart-during-flush` |
| Batch NACK, including retries remaining | `testBatchDoesNotRepublishWhenTheBrokerNacksAMessage` | — | `batch-nack-overflow` |
| Batch confirm timeout re-waits, no nested retry budget | `testFlushWaitsAgainWithoutRepublishingWhenPendingAcksTimeOut`, `testConfirmWaitDoesNotNestTheDefaultRetryBudget` | — | `confirm-timeout-rewait` |
| Confirm timeout then a newer publish re-waits first | `testPublishReWaitsAPendingBatchConfirmBeforeSendingANewerMessage` | — | `confirm-timeout-rewait` |
| Confirm wait connection death republishes | `testFlushRepublishesBatchWhenConnectionClosesWhileWaitingForPendingAcks` | — | `broker-restart-during-flush` |
| Retained batch before a newer direct publish | `testDirectPublishWaitsForARetainedBatchToFlushFirst` | `testRetainedBatchFlushesBeforeANewerDirectPublishAfterTerminalFailure` | `retained-batch-before-direct` |
| Auto-flush after a failed flush at the threshold | `testAutoFlushStillFiresAfterAFailedFlushRetainedTheBatch` | `testAutoFlushRecoversWhenAFailedBatchAlreadyReachedTheThreshold` | `auto-flush-after-failed-flush` |
| Memory alarm / blocked connection (publish and flush) | `testRepeatedPublishesWhileBrokerIsBlockedDoNotKeepOpeningChannels`, `testRepeatedFlushesWhileBrokerIsBlockedDoNotKeepOpeningChannels` | — | `memory-alarm`, `memory-alarm-flush` |
| RetryFactory policy (retry IO/timeout/closed; never nack/blocked/TransportException) | `RetryFactoryTest` | — | `nack-overflow` |
| Delay topology reconnect | `testPublishWithDelayReconnectsOnStaleConnection` | `testDelayedMessages` | `delay-setup-after-broker-restart` |
| Consumer ack isolated from publisher channel retirement | `testPublisherFailureDoesNotInvalidateConsumerOnSharedConnection` | `testPublisherChannelRetirementDoesNotInvalidateAConsumerAcknowledgement` | `consumer-ack-survives-publisher-nack`, `consumer-recovers-after-broker-restart` |
| Confirms disabled | `testPublishWithConfirmDisabled`, `testFlushWithConfirmDisabled` | `LiveConnectionTest::testPublishAndFlushWithConfirmsDisabled`, `testTransportWithTransactions` | — |
| Wrong password | `DsnParserTest` (config only) | `LiveConnectionTest::testConnectFailsWithTheWrongPassword` | — |
| Heartbeat idle / stall | — | `LiveConnectionTest::testPublishStillWorksAfterAnIdleHeartbeatInterval` | `heartbeat-stall` |
| TLS (`phpamqplibs://`) | `AmqpConnectionFactoryTest`, `DsnParserTest` | — | `ssl-publish` |

## Adding a scenario

1. Add a class under `tests/Chaos/Scenario/` with `DESCRIPTION` and `run(Harness $harness): void`.
2. Register it in `tests/Chaos/Scenarios.php`.
3. Use unique topology names from `$harness->topologyName(...)`.
4. Inject faults only through `$harness->broker()` / `$harness->brokerLater()`.
5. Throw via `$harness->fail()` / assertions so PHPUnit can print `FAIL`.

`Harness::cleanup()` unpauses the broker, clears a memory alarm, and deletes the scenario's exchange/queue.

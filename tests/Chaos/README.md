# Live broker chaos tests

These are PHPUnit tests in the `chaos` testsuite. They talk to the docker compose broker, mutate it (restart, pause, overflow, memory alarm), and assert at-least-once publish behaviour.

They are **excluded from the default suite** (`./vendor/bin/phpunit`) so they do not pause or restart the broker used by functional tests. CI runs them in a separate job.

`Harness` is a fixture helper (unique topology names, DSN, consume, broker faults). Assertions are PHPUnit's.

## When to run them

Use these after changing publish, flush, confirm, retry, or channel-retirement code — especially when an agent needs to prove a failure mode that mocks only simulate.

Do not run them at the same time as the default PHPUnit suite. They pause and restart the same RabbitMQ instance.

## How to run

```bash
docker compose up -d --wait
# or: tests/bin/chaos-broker up

./vendor/bin/phpunit --testsuite chaos
./vendor/bin/phpunit --testsuite chaos --filter NackTest
# wrappers:
php tests/bin/chaos.php --list
php tests/bin/chaos.php NackTest
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

## Tests

| Test | What it proves |
|---|---|
| `SmokeTest::testPublishEnqueuesOneMessage` | The broker is reachable and a publish enqueues |
| `NackTest::testDirectPublishNackFromOverflowIsNotRetried` | `x-overflow: reject-publish` becomes `PublisherNack` and is **not** retried |
| `NackTest::testBatchFlushNackFromOverflowIsNotRetriedOrReplayed` | A batch flush NACK keeps the buffer and does not auto-replay into the full queue |
| `NackTest::testConsumerAckSurvivesPublisherNack` | A publisher NACK does not invalidate an outstanding consumer delivery tag |
| `ConfirmTimeoutTest::testBatchConfirmTimeoutRewaitsWithoutRepublishing` | A live batch confirm timeout keeps the channel, re-waits, then a later publish does not duplicate |
| `ConfirmTimeoutTest::testDirectConfirmTimeoutKeepsChannelAndDoesNotRepublish` | A live direct-publish confirm timeout keeps the channel and does not republish |
| `BrokerRestartTest::testRetainedBatchFlushesAfterBrokerRestart` | A retained in-memory batch still flushes after restart |
| `BrokerRestartTest::testInFlightFlushSurvivesBrokerRestart` | An in-flight flush never becomes a silent empty-channel success (at-least-once; duplicates allowed) |
| `BrokerRestartTest::testDirectPublishRecoversAfterBrokerRestart` | A later direct publish recovers after restart |
| `BrokerRestartTest::testConsumerAcksAfterBrokerRestart` | A persistent message can be consumed and acked after restart |
| `BrokerRestartTest::testDelayTopologyIsRecreatedAfterBrokerRestart` | Delayed publish recreates delay topology after restart |
| `MemoryAlarmTest::testPublishFailsDuringMemoryAlarm` | A blocked connection fails the publish instead of succeeding or opening extra channels |
| `MemoryAlarmTest::testFlushKeepsBatchDuringMemoryAlarm` | A blocked flush keeps the batch, then publishes it after the alarm clears |
| `RetainedBatchTest::testFailedBatchFlushesBeforeLaterDirectPublish` | After a failed flush, a later direct publish flushes the old batch first |
| `RetainedBatchTest::testAutoFlushAfterFailedFlushAtThreshold` | After a failed auto-flush at the threshold, a later publish still flushes the retained batch |
| `HeartbeatStallTest::testPublishRecoversAfterHeartbeatStall` | A heartbeat-enabled connection recovers after the broker is paused |
| `SslPublishTest::testPublishAndConsumeOverTls` | `phpamqplibs://` publish/consume works against the compose TLS listener |

Live cases that do **not** mutate the broker run in the default suite (`LiveConnectionTest`): confirms-disabled publish/flush, isolated channel close, wrong-password auth, and idle heartbeat.

## Failure coverage

How the important `Connection` / `RetryFactory` failure paths are locked:

| Failure | Unit | Functional (live broker) | Chaos (mutates broker) |
|---|---|---|---|
| Direct publish connection/channel death | `testDirectPublishRetriesWhenTheConnectionCloses` | `testDirectPublishRecoversAfterBrokerSocketIsDropped` | `BrokerRestartTest::testDirectPublishRecoversAfterBrokerRestart` |
| Isolated publisher/consumer channel close | `testFlushRepublishesBatchAfterChannelClosed`, `testConsumerResumesWhenItsChannelClosesOnALiveConnection` | `LiveConnectionTest` channel-close tests | — |
| Direct publisher NACK, including retries remaining | `testDirectPublishDoesNotRetryWhenTheBrokerNacksTheMessage` | — | `NackTest::testDirectPublishNackFromOverflowIsNotRetried` |
| Direct confirm timeout does not republish (retries remaining) | `testDirectPublishDoesNotRepublishWhenPendingAcksTimeOutWhileRetriesRemain` | — | `ConfirmTimeoutTest::testDirectConfirmTimeoutKeepsChannelAndDoesNotRepublish` |
| Batch flush connection/channel death | `testFlushRepublishesBatchAfterConnectionClosed` | `testBatchFlushRecoversAfterBrokerSocketIsDropped` | `BrokerRestartTest::testRetainedBatchFlushesAfterBrokerRestart`, `BrokerRestartTest::testInFlightFlushSurvivesBrokerRestart` |
| Batch NACK, including retries remaining | `testBatchDoesNotRepublishWhenTheBrokerNacksAMessage` | — | `NackTest::testBatchFlushNackFromOverflowIsNotRetriedOrReplayed` |
| Batch confirm timeout re-waits, no nested retry budget | `testFlushWaitsAgainWithoutRepublishingWhenPendingAcksTimeOut`, `testConfirmWaitDoesNotNestTheDefaultRetryBudget` | — | `ConfirmTimeoutTest::testBatchConfirmTimeoutRewaitsWithoutRepublishing` |
| Confirm timeout then a newer publish re-waits first | `testPublishReWaitsAPendingBatchConfirmBeforeSendingANewerMessage` | — | `ConfirmTimeoutTest::testBatchConfirmTimeoutRewaitsWithoutRepublishing` |
| Confirm wait connection death republishes | `testFlushRepublishesBatchWhenConnectionClosesWhileWaitingForPendingAcks` | — | `BrokerRestartTest::testInFlightFlushSurvivesBrokerRestart` |
| Retained batch before a newer direct publish | `testDirectPublishWaitsForARetainedBatchToFlushFirst` | `testRetainedBatchFlushesBeforeANewerDirectPublishAfterTerminalFailure` | `RetainedBatchTest::testFailedBatchFlushesBeforeLaterDirectPublish` |
| Auto-flush after a failed flush at the threshold | `testAutoFlushStillFiresAfterAFailedFlushRetainedTheBatch` | `testAutoFlushRecoversWhenAFailedBatchAlreadyReachedTheThreshold` | `RetainedBatchTest::testAutoFlushAfterFailedFlushAtThreshold` |
| Memory alarm / blocked connection (publish and flush) | `testRepeatedPublishesWhileBrokerIsBlockedDoNotKeepOpeningChannels`, `testRepeatedFlushesWhileBrokerIsBlockedDoNotKeepOpeningChannels` | — | `MemoryAlarmTest` |
| RetryFactory policy (retry IO/timeout/closed; never nack/blocked/TransportException) | `RetryFactoryTest` | — | `NackTest::testDirectPublishNackFromOverflowIsNotRetried` |
| Delay topology reconnect | `testPublishWithDelayReconnectsOnStaleConnection` | `testDelayedMessages` | `BrokerRestartTest::testDelayTopologyIsRecreatedAfterBrokerRestart` |
| Consumer ack isolated from publisher channel retirement | `testPublisherFailureDoesNotInvalidateConsumerOnSharedConnection` | `testPublisherChannelRetirementDoesNotInvalidateAConsumerAcknowledgement` | `NackTest::testConsumerAckSurvivesPublisherNack`, `BrokerRestartTest::testConsumerAcksAfterBrokerRestart` |
| Confirms disabled | `testPublishWithConfirmDisabled`, `testFlushWithConfirmDisabled` | `LiveConnectionTest::testPublishAndFlushWithConfirmsDisabled`, `testTransportWithTransactions` | — |
| Wrong password | `DsnParserTest` (config only) | `LiveConnectionTest::testConnectFailsWithTheWrongPassword` | — |
| Heartbeat idle / stall | — | `LiveConnectionTest::testPublishStillWorksAfterAnIdleHeartbeatInterval` | `HeartbeatStallTest` |
| TLS (`phpamqplibs://`) | `AmqpConnectionFactoryTest`, `DsnParserTest` | — | `SslPublishTest` |

## Adding a test

1. Add a `*Test.php` class under `tests/Chaos/` that extends `ChaosTestCase`.
2. Use unique topology names from `$this->harness->topologyName(...)`.
3. Inject faults only through `$this->harness->broker()` / `$this->harness->brokerLater()`.
4. Assert with PHPUnit (`self::assertSame`, `self::fail`, …).

`Harness::cleanup()` (called from `ChaosTestCase::tearDown()`) unpauses the broker, clears a memory alarm, and deletes the test's exchange/queue.

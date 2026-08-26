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

./vendor/bin/phpunit --testsuite chaos
./vendor/bin/phpunit --testsuite chaos --filter NackTest
# or: composer chaos

docker compose down
```

Override the DSN if needed:

```bash
MESSENGER_TRANSPORT_PHPAMQPLIB_DSN='phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages' \
  ./vendor/bin/phpunit --testsuite chaos
```

TLS tests use port **5671** (`MESSENGER_TRANSPORT_PHPAMQPLIB_SSL_DSN` if you need to override it).

## Broker faults

Tests inject faults through `$this->harness->broker()` / `$this->harness->brokerLater()`, which run `tests/bin/chaos-broker`. That script is not a test runner; start and stop the broker with `docker compose`.

| Command | Effect |
|---|---|
| `restart` | Kill and bring RabbitMQ back |
| `stop` / `start` | Stop without removing the container |
| `kill` | SIGKILL the container so it cannot drain in-flight confirms |
| `pause` / `unpause` | Freeze the broker so confirms never arrive |
| `memory-alarm` / `memory-ok` | Force / clear a memory `connection.blocked` |
| `disk-alarm` / `disk-ok` | Force / clear a disk `connection.blocked` (`mem_relative 1000` / restore `50MB`) |

## Tests

| Test | What it proves |
|---|---|
| `SmokeTest::testPublishEnqueuesOneMessage` | The broker is reachable and a publish enqueues |
| `NackTest::testDirectPublishNackFromOverflowIsNotRetried` | `x-overflow: reject-publish` becomes `PublisherNack` and is **not** retried |
| `NackTest::testBatchFlushNackFromOverflowIsNotRetriedOrReplayed` | A batch flush NACK keeps the buffer and does not auto-replay into the full queue |
| `NackTest::testConsumerAckSurvivesPublisherNack` | A publisher NACK discards only the publisher channel; the consumer channel and delivery tag stay valid |
| `ConfirmTimeoutTest::testBatchConfirmTimeoutRewaitsWithoutRepublishing` | A live batch confirm timeout keeps the channel, re-waits, then a later publish does not duplicate |
| `ConfirmTimeoutTest::testDirectConfirmTimeoutKeepsChannelAndDoesNotRepublish` | A live direct-publish confirm timeout (`AMQPTimeoutException`, retries 0) keeps the channel and does not republish |
| `BrokerRestartTest::testRetainedBatchFlushesAfterBrokerRestart` | A retained in-memory batch still flushes after restart |
| `BrokerRestartTest::testInFlightFlushSurvivesBrokerRestart` | An in-flight flush with retries 0 retains the batch when it throws (no silent empty retry); at-least-once after recovery |
| `BrokerRestartTest::testDirectPublishRecoversAfterBrokerRestart` | A later direct publish recovers after restart |
| `BrokerRestartTest::testConsumerAcksAfterBrokerRestart` | A persistent message is consumed with a single `consume()` (RetryFactory reconnect) and acked after restart |
| `BrokerRestartTest::testDelayTopologyIsRecreatedAfterBrokerRestart` | Delayed publish recreates delay topology after restart |
| `BrokerRestartTest::testConsumeWaitRecoversAfterBrokerRestart` | `consume()` blocked in `wait()` on an empty queue calls `Connection::close()`, then recovers after restart |
| `BrokerRestartTest::testAutoSetupDoesNotRecreateNonDurableTopologyAfterRestart` | After the first `setup()`, `auto_setup` stays false; non-durable topology is gone until `setup()` runs again |
| `BrokerRestartTest::testBusBatchFlushSurvivesBrokerRestart` | `Batch` through `AmqpTransport` retains the buffer when flush throws with retries 0; at-least-once after recovery |
| `MemoryAlarmTest::testPublishFailsDuringMemoryAlarm` | After `connection.blocked`, publish fails before opening or discarding a publisher channel |
| `MemoryAlarmTest::testFlushKeepsBatchDuringMemoryAlarm` | A blocked flush fails before opening or discarding a publisher channel, keeps the batch, then publishes it after the alarm clears |
| `DiskAlarmTest::testPublishFailsDuringDiskAlarm` | After `connection.blocked`, a disk alarm fails publish the same way, without replacing the publisher channel |
| `DiskAlarmTest::testFlushKeepsBatchDuringDiskAlarm` | A disk-alarmed flush fails before opening or discarding a publisher channel, keeps the batch, then publishes it after the alarm clears |
| `RetainedBatchTest::testFailedBatchFlushesBeforeLaterDirectPublish` | After a failed flush, a later direct publish flushes the old batch first |
| `RetainedBatchTest::testAutoFlushAfterFailedFlushAtThreshold` | After a failed auto-flush at the threshold, a later publish still flushes the retained batch |
| `HeartbeatStallTest::testPublishRecoversAfterHeartbeatStall` | A heartbeat-enabled connection recovers after the broker is paused |
| `HeartbeatStallTest::testConsumeWaitRecoversAfterHeartbeatStall` | `consume()` blocked in `wait()` hits I/O (not `wait_timeout`), calls `Connection::close()`, then recovers after unpause |
| `SslPublishTest::testPublishAndConsumeOverTls` | `phpamqplibs://` publish/consume works against the compose TLS listener |
| `SslPublishTest::testPublishFailsWhenPeerVerificationRejectsTheSelfSignedCertificate` | `verify_peer: true` without a trusted CA rejects the self-signed compose cert |

Live cases that do **not** mutate the broker run in the default suite (`ConnectionLiveTest`): confirms-disabled publish/flush, isolated channel close, ack/nack/decode-fail, auto_setup until `setup()`, abandoned in-memory batch, wrong-password auth, and idle heartbeat.

## Failure coverage

How the important `Connection` / `RetryFactory` failure paths are locked:

| Failure | Unit | Functional (live broker) | Chaos (mutates broker) |
|---|---|---|---|
| Direct publish connection/channel death | `testDirectPublishRetriesWhenTheConnectionCloses`, `testDirectPublishRetriesWhenTheChannelCloses`, `testDirectPublishRetriesWhenIOFails`, `testDirectPublishReconnectsWhenTheConnectionIsDead` | `testDirectPublishRecoversAfterBrokerSocketIsDropped`, `ConnectionLiveTest` channel-close | `BrokerRestartTest::testDirectPublishRecoversAfterBrokerRestart` |
| Isolated publisher/consumer channel close | `testFlushRepublishesBatchAfterChannelClosed`, `testConsumerResumesWhenItsChannelClosesOnALiveConnection` | `ConnectionLiveTest` channel-close tests | — |
| Direct publisher NACK, including retries remaining | `testDirectPublishDoesNotRetryWhenTheBrokerNacksTheMessage` | `ConnectionLiveTest::testDirectPublishNackFromOverflowIsNotRetried` | `NackTest::testDirectPublishNackFromOverflowIsNotRetried` |
| Direct confirm timeout does not republish (retries remaining) | `testDirectPublishDoesNotRepublishWhenPendingAcksTimeOutWhileRetriesRemain` | — | `ConfirmTimeoutTest::testDirectConfirmTimeoutKeepsChannelAndDoesNotRepublish` |
| Batch flush connection/channel death | `testFlushRepublishesBatchAfterConnectionClosed` | `testBatchFlushRecoversAfterBrokerSocketIsDropped` | `BrokerRestartTest::testRetainedBatchFlushesAfterBrokerRestart`, `BrokerRestartTest::testInFlightFlushSurvivesBrokerRestart` |
| Batch NACK, including retries remaining | `testBatchDoesNotRepublishWhenTheBrokerNacksAMessage` | `ConnectionLiveTest::testBatchFlushNackFromOverflowKeepsTheBuffer` | `NackTest::testBatchFlushNackFromOverflowIsNotRetriedOrReplayed` |
| Batch confirm timeout re-waits, no nested retry budget | `testFlushWaitsAgainWithoutRepublishingWhenPendingAcksTimeOut`, `testConfirmWaitDoesNotNestTheDefaultRetryBudget` | — | `ConfirmTimeoutTest::testBatchConfirmTimeoutRewaitsWithoutRepublishing` |
| Confirm timeout then a newer publish re-waits first | `testPublishReWaitsAPendingBatchConfirmBeforeSendingANewerMessage` | — | `ConfirmTimeoutTest::testBatchConfirmTimeoutRewaitsWithoutRepublishing` |
| Confirm wait connection death republishes | `testFlushRepublishesBatchWhenConnectionClosesWhileWaitingForPendingAcks`, `testDirectPublishRetriesWhenTheConnectionClosesWhileWaitingForPendingAcks` | — | `BrokerRestartTest::testInFlightFlushSurvivesBrokerRestart` |
| Retained batch before a newer direct publish | `testDirectPublishWaitsForARetainedBatchToFlushFirst` | `testRetainedBatchFlushesBeforeANewerDirectPublishAfterTerminalFailure` | `RetainedBatchTest::testFailedBatchFlushesBeforeLaterDirectPublish` |
| Auto-flush after a failed flush at the threshold | `testAutoFlushStillFiresAfterAFailedFlushRetainedTheBatch` | `testAutoFlushRecoversWhenAFailedBatchAlreadyReachedTheThreshold` | `RetainedBatchTest::testAutoFlushAfterFailedFlushAtThreshold` |
| Memory alarm / blocked connection (publish and flush) | `testRepeatedPublishesWhileBrokerIsBlockedDoNotKeepOpeningChannels`, `testRepeatedFlushesWhileBrokerIsBlockedDoNotKeepOpeningChannels` | — | `MemoryAlarmTest` |
| Disk alarm / blocked connection (publish and flush) | — | — | `DiskAlarmTest` |
| RetryFactory policy (retry IO/timeout/closed; never nack/blocked/TransportException) | `RetryFactoryTest` | — | `NackTest::testDirectPublishNackFromOverflowIsNotRetried` |
| Delay topology reconnect | `testPublishWithDelayReconnectsOnStaleConnection` | `testDelayedMessages` | `BrokerRestartTest::testDelayTopologyIsRecreatedAfterBrokerRestart` |
| RedeliveryStamp delayed retry is not batched | — | `testRedeliveryStampUsesDelayTopologyAndDoesNotBatch` | — |
| Consume blocked in `wait()` during broker fault | — | — | `BrokerRestartTest::testConsumeWaitRecoversAfterBrokerRestart`, `HeartbeatStallTest::testConsumeWaitRecoversAfterHeartbeatStall` |
| `Batch` through the bus during broker restart | — | `testBatchFlushRecoversAfterBrokerSocketIsDropped` | `BrokerRestartTest::testBusBatchFlushSurvivesBrokerRestart` |
| `auto_setup` latches false (missing topology until `setup()`) | — | `ConnectionLiveTest::testPublishFailsWhenAutoSetupIsDisabledUntilSetupIsCalled` | `BrokerRestartTest::testAutoSetupDoesNotRecreateNonDurableTopologyAfterRestart` |
| Ack / nack drop / decode-fail nack | `AmqpReceiverTest` decode-fail nack tests | `ConnectionLiveTest` ack/nack/decode tests, `testRejectDoesNotRequeueTheMessage`, `testDecodeFailureNacksTheUndecodableMessage` | — |
| Consumer ack/reject isolated from publisher channel retirement | `testPublisherFailureDoesNotInvalidateConsumerOnSharedConnection`, `AmqpConsumerTest::testInvalidateDropsTheConsumerTagAndBufferedEnvelopes` | `testPublisherChannelRetirementDoesNotInvalidateAConsumerAcknowledgement`, `testPublisherChannelRetirementDoesNotInvalidateAConsumerReject`, `ConnectionLiveTest` ack/nack after publisher close, `ConnectionLiveTest::testConsumerAckSurvivesPublisherNack` | `NackTest::testConsumerAckSurvivesPublisherNack`, `BrokerRestartTest::testConsumerAcksAfterBrokerRestart` |
| Confirms disabled | `testPublishWithConfirmDisabled`, `testFlushWithConfirmDisabled` | `ConnectionLiveTest::testPublishAndFlushWithConfirmsDisabled`, `testTransportWithTransactions` | — |
| Wrong password | `DsnParserTest` (config only) | `ConnectionLiveTest::testConnectFailsWithTheWrongPassword` | — |
| Heartbeat idle / stall | — | `ConnectionLiveTest::testPublishStillWorksAfterAnIdleHeartbeatInterval` | `HeartbeatStallTest` |
| TLS (`phpamqplibs://`) | `AmqpConnectionFactoryTest`, `DsnParserTest` | — | `SslPublishTest` |
| Abandoned in-memory batch is not on the broker | — | `ConnectionLiveTest::testUnflushedBatchIsNotOnTheBrokerAfterTheConnectionIsAbandoned` | — |

## Adding a test

1. Add a `*Test.php` class under `tests/Chaos/` that extends `ChaosTestCase`.
2. Use unique topology names from `$this->harness->topologyName(...)`.
3. Inject faults only through `$this->harness->broker()` / `$this->harness->brokerLater()`.
4. Assert with PHPUnit (`self::assertSame`, `self::fail`, …).

`Harness::cleanup()` (called from `ChaosTestCase::tearDown()`) unpauses the broker, clears memory and disk alarms, and deletes the test's exchange/queue.

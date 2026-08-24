<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos;

use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\AutoFlushAfterFailedFlush;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\BatchNackOverflow;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\BrokerRestartBeforeFlush;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\BrokerRestartDuringFlush;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\ConfirmTimeoutRewait;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\ConsumerAckSurvivesPublisherNack;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\ConsumerRecoversAfterBrokerRestart;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\DelaySetupAfterBrokerRestart;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\DirectConfirmTimeoutRewait;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\DirectPublishAfterBrokerRestart;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\HeartbeatStall;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\MemoryAlarm;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\MemoryAlarmFlush;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\NackOverflow;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\RetainedBatchBeforeDirect;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\Smoke;
use Jwage\PhpAmqpLibMessengerBundle\Tests\Chaos\Scenario\SslPublish;

final class Scenarios
{
    /** @return array<string, class-string> */
    public static function all(): array
    {
        return [
            'smoke' => Smoke::class,
            'nack-overflow' => NackOverflow::class,
            'batch-nack-overflow' => BatchNackOverflow::class,
            'confirm-timeout-rewait' => ConfirmTimeoutRewait::class,
            'direct-confirm-timeout-rewait' => DirectConfirmTimeoutRewait::class,
            'broker-restart-before-flush' => BrokerRestartBeforeFlush::class,
            'broker-restart-during-flush' => BrokerRestartDuringFlush::class,
            'direct-publish-after-broker-restart' => DirectPublishAfterBrokerRestart::class,
            'consumer-recovers-after-broker-restart' => ConsumerRecoversAfterBrokerRestart::class,
            'delay-setup-after-broker-restart' => DelaySetupAfterBrokerRestart::class,
            'memory-alarm' => MemoryAlarm::class,
            'memory-alarm-flush' => MemoryAlarmFlush::class,
            'retained-batch-before-direct' => RetainedBatchBeforeDirect::class,
            'auto-flush-after-failed-flush' => AutoFlushAfterFailedFlush::class,
            'consumer-ack-survives-publisher-nack' => ConsumerAckSurvivesPublisherNack::class,
            'heartbeat-stall' => HeartbeatStall::class,
            'ssl-publish' => SslPublish::class,
        ];
    }
}

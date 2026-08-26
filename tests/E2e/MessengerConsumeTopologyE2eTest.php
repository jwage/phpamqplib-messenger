<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\E2e;

use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Envelope;
use Throwable;

use function array_column;
use function usort;

#[Group('live')]
#[Group('e2e')]
class MessengerConsumeTopologyE2eTest extends E2eTestCase
{
    public function testQueuesOptionConsumesOnlyTheNamedQueue(): void
    {
        $order = $this->uniqueId('order');
        $quote = $this->uniqueId('quote');

        $this->bus()->dispatch(Envelope::wrap(new E2eRoutedMessage($order))->with(new AmqpStamp(routingKey: 'order')));
        $this->bus()->dispatch(Envelope::wrap(new E2eRoutedMessage($quote))->with(new AmqpStamp(routingKey: 'quote')));

        $this->startConsume(['e2e_multi'], limit: 1, extra: ['--queues=' . $this->orderQueue]);
        $this->assertConsumeExitsSuccessfully();

        $handled = $this->recordsOfType(E2eRoutedMessage::class);
        self::assertSame([$order], $this->idsOf($handled));
        self::assertSame($this->orderQueue, $handled[0]['queue'] ?? null);
        self::assertSame(1, $this->messageCount('e2e_multi'));
    }

    public function testBothBoundQueuesAreConsumedWithoutQueuesOption(): void
    {
        $order = $this->uniqueId('order-all');
        $quote = $this->uniqueId('quote-all');

        $this->bus()->dispatch(Envelope::wrap(new E2eRoutedMessage($order))->with(new AmqpStamp(routingKey: 'order')));
        $this->bus()->dispatch(Envelope::wrap(new E2eRoutedMessage($quote))->with(new AmqpStamp(routingKey: 'quote')));

        $this->startConsume(['e2e_multi'], limit: 2);
        $this->assertConsumeExitsSuccessfully();

        self::assertEqualsCanonicalizing([$order, $quote], $this->idsOf($this->recordsOfType(E2eRoutedMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_multi');
    }

    public function testSetupTransportsDeclaresAManualTopology(): void
    {
        $id = $this->uniqueId('manual');

        try {
            $this->bus()->dispatch(new E2eManualMessage($id));
            $published = true;
        } catch (Throwable) {
            $published = false;
        }

        self::assertFalse($published, 'auto_setup: false should reject publish before setup-transports');

        self::assertSame(0, $this->runConsole(['messenger:setup-transports', 'e2e_manual']));

        $this->bus()->dispatch(new E2eManualMessage($id));
        $this->startConsume(['e2e_manual'], limit: 1);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eManualMessage::class)));
    }

    public function testDispatchAutoSetupDeclaresTopologyWithoutTestSetup(): void
    {
        $id = $this->uniqueId('auto');
        $this->bus()->dispatch(new E2eAutoMessage($id));

        $this->startConsume(['e2e_auto'], limit: 1);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eAutoMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_auto');
    }

    public function testTransactionsTransportIsConsumed(): void
    {
        $id = $this->uniqueId('tx');
        $this->bus()->dispatch(new E2eTxMessage($id));

        $this->startConsume(['e2e_tx'], limit: 1);
        $this->assertConsumeExitsSuccessfully();

        self::assertSame([$id], $this->idsOf($this->recordsOfType(E2eTxMessage::class)));
        $this->assertQueueEventuallyEmpty('e2e_tx');
    }

    public function testHighTransportIsHandledFirstWhenBothHaveWork(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->bus()->dispatch(new E2eMessage($this->uniqueId('h')));
            $this->bus()->dispatch(new E2eLowMessage($this->uniqueId('l')));
        }

        $this->startConsume(['e2e_high', 'e2e_low'], limit: 8);
        $this->assertConsumeExitsSuccessfully();

        $all = [];

        foreach ([...$this->recordsOfType(E2eMessage::class), ...$this->recordsOfType(E2eLowMessage::class)] as $record) {
            $all[] = $record;
        }

        usort($all, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);

        self::assertSame(E2eMessage::class, $all[0]['type']);
        self::assertContains(E2eLowMessage::class, array_column($all, 'type'));
        self::assertCount(8, $all);
    }

    public function testBusyFirstTransportIsDrainedBeforeTheSecondIsPolled(): void
    {
        // e2e_greedy prefetches 20, so the first transport still has client-buffered
        // deliveries after fetch_size=1 and Worker restarts from that receiver.
        for ($i = 0; $i < 6; $i++) {
            $this->bus()->dispatch(new E2eGreedyMessage($this->uniqueId('busy')));
        }

        $low = $this->uniqueId('fair-low');
        $this->bus()->dispatch(new E2eLowMessage($low));

        $this->startConsume(['e2e_greedy', 'e2e_low'], limit: 7);
        $this->assertConsumeExitsSuccessfully();

        $lowRecords = $this->recordsOfType(E2eLowMessage::class);
        self::assertSame([$low], $this->idsOf($lowRecords));

        $high = $this->recordsOfType(E2eGreedyMessage::class);
        self::assertCount(6, $high);
        self::assertGreaterThan($high[5]['t'], $lowRecords[0]['t']);
    }

    public function testLargeFetchSizeHandlesTheFirstTransportAsABatch(): void
    {
        $extra = $this->consumeSupports('--fetch-size') ? ['--fetch-size=20'] : [];

        for ($i = 0; $i < 4; $i++) {
            $this->bus()->dispatch(new E2eGreedyMessage($this->uniqueId('g')));
        }

        $low = $this->uniqueId('after-greedy');
        $this->bus()->dispatch(new E2eLowMessage($low));

        $this->startConsume(['e2e_greedy', 'e2e_low'], limit: 5, extra: $extra);
        $this->assertConsumeExitsSuccessfully();

        $all = [...$this->recordsOfType(E2eGreedyMessage::class), ...$this->recordsOfType(E2eLowMessage::class)];
        usort($all, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);

        self::assertCount(5, $all);
        self::assertSame(E2eGreedyMessage::class, $all[0]['type']);
        self::assertSame(E2eGreedyMessage::class, $all[3]['type']);
        self::assertSame(E2eLowMessage::class, $all[4]['type']);
    }
}

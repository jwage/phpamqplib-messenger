<?php

namespace Jwage\PhpAmqpLibMessengerBundle\Middleware;

use Jwage\PhpAmqpLibMessengerBundle\Stamp\Deferred;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\Flush;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

final class AmqpFlushMiddlware implements MiddlewareInterface
{
    /** @var array<BatchTransportInterface> */
    private array $transportsToFlush = [];

    public function __construct(
        private SendersLocatorInterface $sendersLocator,
    )
    {
    }
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->getMessage() instanceof Flush) {
            foreach ($this->transportsToFlush as $envelope) {
                foreach ($this->sendersLocator->getSenders($envelope) as $transport) {
                    if (!$transport instanceof BatchTransportInterface) {
                        continue;
                    }
                    $transport->flush();
                }
            }
            return $envelope;
        }


        $envelope = $stack->next()->handle($envelope, $stack);

        if (($stamp = $envelope->last(Deferred::class)) !== null) {
            if (($sent = $envelope->last(SentStamp::class))) {
                $this->transportsToFlush[$sent->getSenderAlias()] = $envelope;
            }
        }

        return $envelope;
    }
}

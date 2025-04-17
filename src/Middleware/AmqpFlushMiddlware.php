<?php

namespace Jwage\PhpAmqpLibMessengerBundle\Middleware;

use Jwage\PhpAmqpLibMessengerBundle\Stamp\Deferred;
use Jwage\PhpAmqpLibMessengerBundle\Stamp\Flush;
use Jwage\PhpAmqpLibMessengerBundle\Transport\BatchTransportInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class AmqpFlushMiddlware implements MiddlewareInterface
{
    /** @var array<BatchTransportInterface> */
    private array $transportsToFlush = [];

    public function __construct()
    {
    }
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->getMessage() instanceof Flush) {
            foreach ($this->transportsToFlush as $transport) {
                $transport->flush();
            }
            return $envelope;
        }


        $envelope = $stack->next()->handle($envelope, $stack);

        if (($stamp = $envelope->last(Deferred::class)) !== null) {
            $transport = $stamp->getTransport();
            $this->transportsToFlush[] = $transport;
        }

        return $envelope;
    }
}

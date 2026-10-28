<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;

use function interface_exists;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable Generic.Files.OneObjectStructurePerFile

if (interface_exists(KeepaliveReceiverInterface::class)) {
    /**
     * @internal
     *
     * Adapter interface that conditionally extends KeepaliveReceiverInterface
     * when available (Symfony >= 7.2). On older versions, this is a no-op interface.
     */
    interface AmqpKeepaliveReceiverInterface extends KeepaliveReceiverInterface
    {
        public function keepalive(Envelope $envelope, int|null $seconds = null): void;
    }
} else {
    /**
     * @internal
     *
     * Fallback interface for Symfony < 7.2 where KeepaliveReceiverInterface does not exist.
     */
    interface AmqpKeepaliveReceiverInterface
    {
        public function keepalive(Envelope $envelope, int|null $seconds = null): void;
    }
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;

if (interface_exists(KeepaliveReceiverInterface::class)) {
    /**
     * @internal
     *
     * Adapter interface that conditionally extends KeepaliveReceiverInterface
     * when available (Symfony >= 7.2). On older versions, this is a no-op interface.
     */
    interface AmqpKeepaliveReceiverInterface extends KeepaliveReceiverInterface
    {
    }
} else {
    /**
     * @internal
     *
     * Fallback interface for Symfony < 7.2 where KeepaliveReceiverInterface does not exist.
     */
    interface AmqpKeepaliveReceiverInterface
    {
    }
}

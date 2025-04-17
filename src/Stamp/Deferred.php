<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final class Deferred implements NonSendableStampInterface
{
}

<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use PhpAmqpLib\Exception\AMQPExceptionInterface;
use RuntimeException;

class PublisherNack extends RuntimeException implements AMQPExceptionInterface
{
}

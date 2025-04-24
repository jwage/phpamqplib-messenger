<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\DependencyInjection;

use InvalidArgumentException;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PhpAmqpLibMessengerCompilerPass implements CompilerPassInterface
{
    /** @throws InvalidArgumentException */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
    }
}

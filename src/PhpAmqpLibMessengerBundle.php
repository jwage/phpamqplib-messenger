<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerCompilerPass;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class PhpAmqpLibMessengerBundle extends Bundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new PhpAmqpLibMessengerCompilerPass());
    }
}

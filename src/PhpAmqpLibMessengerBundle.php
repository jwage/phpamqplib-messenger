<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerCompilerPass;
use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerExtension;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;

class PhpAmqpLibMessengerBundle extends AbstractBundle
{
    private PhpAmqpLibMessengerExtension|null $bundleExtension = null;

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new PhpAmqpLibMessengerCompilerPass());
    }

    #[Override]
    public function getContainerExtension(): ExtensionInterface|null
    {
        return $this->bundleExtension ??= new PhpAmqpLibMessengerExtension();
    }
}

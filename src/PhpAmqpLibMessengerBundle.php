<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Jwage\PhpAmqpLibMessengerBundle\Compat\BundleBase;
use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerCompilerPass;
use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerExtension;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

class PhpAmqpLibMessengerBundle extends BundleBase
{
    private PhpAmqpLibMessengerExtension|null $bundleExtension = null;

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new PhpAmqpLibMessengerCompilerPass());
    }

    #[Override]
    public function getContainerExtension(): ExtensionInterface|null
    {
        return $this->bundleExtension ??= new PhpAmqpLibMessengerExtension();
    }
}

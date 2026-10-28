<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle;

use Jwage\PhpAmqpLibMessengerBundle\Compat\BundleBase;
use Jwage\PhpAmqpLibMessengerBundle\DependencyInjection\PhpAmqpLibMessengerExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

class PhpAmqpLibMessengerBundle extends BundleBase
{
    private PhpAmqpLibMessengerExtension|null $bundleExtension = null;

    public function getContainerExtension(): ExtensionInterface|null
    {
        return $this->bundleExtension ??= new PhpAmqpLibMessengerExtension();
    }
}

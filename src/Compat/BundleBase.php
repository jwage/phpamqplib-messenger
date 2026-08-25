<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Compat;

use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use function class_exists;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable Generic.Files.OneObjectStructurePerFile
if (class_exists(AbstractBundle::class)) {
    abstract class BundleBase extends AbstractBundle
    {
    }
} else {
    abstract class BundleBase extends Bundle
    {
    }
}

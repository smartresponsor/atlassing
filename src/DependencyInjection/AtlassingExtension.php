<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class AtlassingExtension extends Extension
{
    /**
     * Registers the Atlas component configuration surface.
     *
     * Service wiring is intentionally host-friendly: the component can run as a standalone
     * Symfony bundle or as a producer component discovered by a host application.
     *
     * @param array<int, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->setParameter('atlassing.component', 'atlassing');
        $container->setParameter('atlassing.package', 'atlassing/atlas');
        $container->setParameter('atlassing.database_prefix', 'atlas_');
        $container->setParameter('atlassing.project_dir', '%kernel.project_dir%');
        $container->setParameter('atlassing.atlas_root', '%kernel.project_dir%/var/atlas');
        $container->setParameter('atlassing.python_engine_dir', '%kernel.project_dir%/tools/atlas/python-engine');

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }
}

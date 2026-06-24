<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<object>
     */
    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir() . '/config/bundles.php';

        foreach ($contents as $class => $environments) {
            if (($environments[$this->environment] ?? false) || ($environments['all'] ?? false)) {
                yield new $class();
            }
        }
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->addResource(new FileResource($this->getProjectDir() . '/config/bundles.php'));
        $loader->load($this->getProjectDir() . '/config/packages/*.yaml', 'glob');
        $loader->load($this->getProjectDir() . '/config/services.yaml');
    }

    protected function configureRoutes(LoaderInterface $loader): void
    {
        $loader->load($this->getProjectDir() . '/config/routes.yaml');
    }
}

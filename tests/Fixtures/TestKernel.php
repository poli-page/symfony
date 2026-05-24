<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Fixtures;

use PoliPage\Symfony\PoliPageBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    private readonly string $instanceId;

    /**
     * @param array<string, mixed> $poliPageConfig
     */
    public function __construct(private readonly array $poliPageConfig = ['api_key' => 'pp_test_dummy_for_kernel_boot'])
    {
        // Why: spl_object_hash collides after GC across instances; use a
        // unique id so each TestKernel instance compiles into its own
        // cache directory, even if a previous instance was just shut down.
        $this->instanceId = bin2hex(random_bytes(8));
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new PoliPageBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);
            $container->loadFromExtension('poli_page', $this->poliPageConfig);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/poli_page_symfony_bundle/cache/'.$this->instanceId;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/poli_page_symfony_bundle/log/'.$this->instanceId;
    }
}

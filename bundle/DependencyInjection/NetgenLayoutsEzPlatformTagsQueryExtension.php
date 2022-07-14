<?php

declare(strict_types=1);

namespace Netgen\Bundle\LayoutsEzPlatformTagsQueryBundle\DependencyInjection;

use Netgen\Layouts\Exception\RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

use function array_key_exists;
use function file_get_contents;

final class NetgenLayoutsEzPlatformTagsQueryExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @param mixed[] $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array<string, string> $activatedBundles */
        $activatedBundles = $container->getParameter('kernel.bundles');

        if (!array_key_exists('NetgenTagsBundle', $activatedBundles)) {
            throw new RuntimeException('Netgen Layouts Tags Query Bundle requires Netgen Tags (netgen/tagsbundle) to be activated to work properly.');
        }

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config'),
        );

        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $prependConfigs = [
            'query_types.yaml' => 'netgen_layouts',
        ];

        foreach ($prependConfigs as $configFile => $prependConfig) {
            $configFile = __DIR__ . '/../Resources/config/' . $configFile;
            $config = Yaml::parse((string) file_get_contents($configFile));
            $container->prependExtensionConfig($prependConfig, $config);
            $container->addResource(new FileResource($configFile));
        }
    }
}

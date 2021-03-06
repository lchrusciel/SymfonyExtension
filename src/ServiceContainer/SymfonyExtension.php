<?php

namespace FriendsOfBehat\SymfonyExtension\ServiceContainer;

use Behat\MinkExtension\ServiceContainer\MinkExtension;
use Behat\Testwork\EventDispatcher\ServiceContainer\EventDispatcherExtension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use FriendsOfBehat\SymfonyExtension\Driver\Factory\SymfonyDriverFactory;
use FriendsOfBehat\SymfonyExtension\Listener\KernelRebooter;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Kamil Kokot <kamil@kokot.me>
 */
final class SymfonyExtension implements Extension
{
    /**
     * Kernel used inside Behat contexts or to create services injected to them.
     * Container is built before every scenario.
     */
    const KERNEL_ID = 'sylius_symfony_extension.kernel';

    /**
     * The current container used in scenario contexts.
     * To be used as a factory for current injected application services.
     */
    const KERNEL_CONTAINER_ID = 'sylius_symfony_extension.kernel.container';

    /**
     * Kernel used by Symfony2 driver to isolate web container from contexts' container.
     * Container is built before every request.
     */
    const DRIVER_KERNEL_ID = 'sylius_symfony_extension.driver_kernel';

    /**
     * Kernel that should be used by extensions only.
     * Container is built only once at the first use.
     */
    const SHARED_KERNEL_ID = 'sylius_symfony_extension.shared_kernel';

    /**
     * The only container built by shared kernel.
     * To be used as a factory for shared injected application services.
     */
    const SHARED_KERNEL_CONTAINER_ID = 'sylius_symfony_extension.shared_kernel.container';

    /**
     * {@inheritdoc}
     */
    public function getConfigKey()
    {
        return 'fob_symfony';
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(ExtensionManager $extensionManager)
    {
        $this->registerSymfonyDriverFactory($extensionManager);
    }

    /**
     * {@inheritdoc}
     */
    public function configure(ArrayNodeDefinition $builder)
    {
        $builder
            ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('kernel')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('bootstrap')->defaultValue('app/autoload.php')->end()
                            ->scalarNode('path')->defaultValue('app/AppKernel.php')->end()
                            ->scalarNode('class')->defaultValue('AppKernel')->end()
                            ->scalarNode('env')->defaultValue('test')->end()
                            ->booleanNode('debug')->defaultTrue()->end()
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function load(ContainerBuilder $container, array $config)
    {
        $this->loadKernel($container, $config['kernel']);
        $this->loadKernelContainer($container);

        $this->loadDriverKernel($container);

        $this->loadSharedKernel($container);
        $this->loadSharedKernelContainer($container);

        $this->loadKernelRebooter($container);
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {

    }

    /**
     * @param ContainerBuilder $container
     */
    private function loadKernel(ContainerBuilder $container, array $config)
    {
        $definition = new Definition($config['class'], array(
            $config['env'],
            $config['debug'],
        ));
        $definition->addMethodCall('boot');
        $container->setDefinition(self::KERNEL_ID, $definition);
        $container->setParameter(self::KERNEL_ID . '.path', $config['path']);
        $container->setParameter(self::KERNEL_ID . '.bootstrap', $config['bootstrap']);
    }

    /**
     * @param ContainerBuilder $container
     */
    private function loadKernelContainer(ContainerBuilder $container)
    {
        $containerDefinition = new Definition(Container::class);
        $containerDefinition->setFactory([
            new Reference(self::KERNEL_ID),
            'getContainer',
        ]);

        $container->setDefinition(self::KERNEL_CONTAINER_ID, $containerDefinition);
    }

    /**
     * @param ContainerBuilder $container
     */
    private function loadDriverKernel(ContainerBuilder $container)
    {
        $container->setDefinition(self::DRIVER_KERNEL_ID, $container->findDefinition(self::KERNEL_ID));
    }

    /**
     * @param ContainerBuilder $container
     */
    private function loadSharedKernel(ContainerBuilder $container)
    {
        $container->setDefinition(self::SHARED_KERNEL_ID, $container->findDefinition(self::KERNEL_ID));
    }

    /**
     * @param ContainerBuilder $container
     */
    private function loadSharedKernelContainer(ContainerBuilder $container)
    {
        $containerDefinition = new Definition(Container::class);
        $containerDefinition->setFactory([
            new Reference(self::SHARED_KERNEL_ID),
            'getContainer',
        ]);

        $container->setDefinition(self::SHARED_KERNEL_CONTAINER_ID, $containerDefinition);
    }

    /**
     * @param ContainerBuilder $container
     */
    private function loadKernelRebooter(ContainerBuilder $container)
    {
        $definition = new Definition(KernelRebooter::class, [new Reference(self::KERNEL_ID)]);
        $definition->addTag(EventDispatcherExtension::SUBSCRIBER_TAG);

        $container->setDefinition(self::KERNEL_ID . '.rebooter', $definition);
    }

    /**
     * @param ExtensionManager $extensionManager
     */
    private function registerSymfonyDriverFactory(ExtensionManager $extensionManager)
    {
        /** @var MinkExtension $minkExtension */
        $minkExtension = $extensionManager->getExtension('mink');
        if (null === $minkExtension) {
            return;
        }

        $minkExtension->registerDriverFactory(new SymfonyDriverFactory(
            'symfony',
            new Reference(self::DRIVER_KERNEL_ID)
        ));
    }
}

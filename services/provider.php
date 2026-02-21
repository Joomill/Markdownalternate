<?php
/*
 *  package: Joomill Markdown Alternate plugin
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | Joomill
 *  license: GNU General Public License version 3 or later
 *  link: https://www.joomill-extensions.com
 */

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomill\Plugin\System\Markdownalternate\Extension\Markdownalternate;

return new class implements ServiceProviderInterface {

    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Markdownalternate(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'markdownalternate')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

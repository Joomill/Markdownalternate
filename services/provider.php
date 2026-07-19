<?php

/**
 * Markdown Alternate
 *
 * @copyright   Copyright (c) 2026 Jeroen Moolenschot | Joomill
 * @license     GNU General Public License version 3 or later; see LICENSE
 * @link        https://www.joomill-extensions.com
 */

declare(strict_types=1);

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
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
        $factory = function (Container $container): PluginInterface {
            $plugin = new Markdownalternate(
                $container->get(DispatcherInterface::class),
                (array) PluginHelper::getPlugin('system', 'markdownalternate')
            );
            $plugin->setApplication(Factory::getApplication());
            $plugin->setDatabase($container->get(DatabaseInterface::class));

            return $plugin;
        };

        // Lazy plugin loading exists from Joomla 6.1; fall back to a plain
        // service on Joomla 5 / 6.0 where Container::lazy() is unavailable.
        $container->set(
            PluginInterface::class,
            method_exists($container, 'lazy')
                ? $container->lazy(Markdownalternate::class, $factory)
                : $factory
        );
    }
};

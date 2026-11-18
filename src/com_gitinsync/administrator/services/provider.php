<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Yepr\Component\GitInSync\Administrator\Extension\GitInSyncComponent;
use Yepr\Component\GitInSync\Administrator\Service\Services;

/**
 * The component's service provider. The services themselves are put together in
 * Service\Services, on first use.
 */
return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->registerServiceProvider(new MVCFactory('\\Yepr\\Component\\GitInSync'));
		$container->registerServiceProvider(new ComponentDispatcherFactory('\\Yepr\\Component\\GitInSync'));

		$container->share(
			Services::class,
			static fn (Container $container): Services => new Services(
				$container->get(DatabaseInterface::class),
				$container->get(UserFactoryInterface::class),
				Factory::getApplication()
			)
		);

		$container->set(
			ComponentInterface::class,
			static function (Container $container): ComponentInterface {
				$component = new GitInSyncComponent($container->get(ComponentDispatcherFactoryInterface::class));
				$component->setMVCFactory($container->get(MVCFactoryInterface::class));
				$component->setServicesFactory(static fn (): Services => $container->get(Services::class));

				return $component;
			}
		);
	}
};

<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Yepr\Plugin\Content\GitInSync\Extension\GitInSync;

/**
 * The plugin is a lazy proxy: it is built when one of its events fires, and it only boots
 * GitInSync's services when the event is about content GitInSync keeps.
 */
return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			$container->lazy(GitInSync::class, function (Container $container) {
				$plugin = new GitInSync((array) PluginHelper::getPlugin('content', 'gitinsync'));
				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			})
		);
	}
};

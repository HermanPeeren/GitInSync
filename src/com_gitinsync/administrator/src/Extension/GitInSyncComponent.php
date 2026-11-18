<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Extension;

use Joomla\CMS\Extension\MVCComponent;
use Yepr\Component\GitInSync\Administrator\Service\Services;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The component: its screens, and the services the plugins use.
 */
final class GitInSyncComponent extends MVCComponent
{
	private ?Services $services = null;

	/**
	 * @var  \Closure(): Services
	 */
	private \Closure $servicesFactory;

	/**
	 * @param   \Closure(): Services  $servicesFactory
	 */
	public function setServicesFactory(\Closure $servicesFactory): void
	{
		$this->servicesFactory = $servicesFactory;
	}

	public function services(): Services
	{
		return $this->services ??= ($this->servicesFactory)();
	}
}

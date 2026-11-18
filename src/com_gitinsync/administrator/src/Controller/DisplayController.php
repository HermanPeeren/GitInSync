<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Shows GitInSync's screens; the dashboard by default.
 */
final class DisplayController extends BaseController
{
	/**
	 * @var string
	 */
	protected $default_view = 'dashboard';

	/**
	 * @param   boolean  $cachable
	 * @param   array<string, mixed>  $urlparams
	 */
	public function display($cachable = false, $urlparams = []): static
	{
		if (!$this->app->getIdentity()?->authorise('core.manage', 'com_gitinsync')) {
			$this->app->enqueueMessage($this->app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 'error');
			$this->setRedirect('index.php');

			return $this;
		}

		return parent::display($cachable, $urlparams);
	}
}

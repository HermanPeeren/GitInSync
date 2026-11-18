<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\View\Run;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Yepr\Component\GitInSync\Administrator\View\BaseView;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Runs a long job (commit, export, push, pull) in portions from the browser.
 */
final class HtmlView extends BaseView
{
	public string $job = '';

	/**
	 * @param   string|null  $tpl
	 */
	public function display($tpl = null): void
	{
		$this->job = Factory::getApplication()->getInput()->getCmd('job', '');

		if (!\in_array($this->job, ['commit', 'export', 'push', 'pull'], true)) {
			$this->job = 'commit';
		}

		$this->toolbar(Text::_('COM_GITINSYNC_JOB_' . strtoupper($this->job)));

		$this->getDocument()->getWebAssetManager()->useScript('com_gitinsync.run');

		parent::display($tpl);
	}
}

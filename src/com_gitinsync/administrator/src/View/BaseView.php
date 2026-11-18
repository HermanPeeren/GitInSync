<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\View;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Yepr\Component\GitInSync\Administrator\Extension\GitInSyncComponent;
use Yepr\Component\GitInSync\Administrator\Service\Services;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What every GitInSync screen shares: the services, the title and the toolbar.
 */
abstract class BaseView extends HtmlView
{
	public ?string $error = null;

	public bool $canApply = false;

	protected function services(): Services
	{
		$component = Factory::getApplication()->bootComponent('com_gitinsync');

		if (!$component instanceof GitInSyncComponent) {
			throw new \RuntimeException('GitInSync is not installed correctly.');
		}

		return $component->services();
	}

	protected function toolbar(string $title): void
	{
		$user           = Factory::getApplication()->getIdentity();
		$this->canApply = (bool) $user?->authorise('gitinsync.apply', 'com_gitinsync');

		ToolbarHelper::title(Text::_('COM_GITINSYNC') . ': ' . $title, 'code-branch');

		$document = $this->getDocument();

		$toolbar = $document instanceof HtmlDocument ? $document->getToolbar() : null;

		if ($toolbar === null) {
			return;
		}

		$toolbar->linkButton('dashboard', 'COM_GITINSYNC_DASHBOARD')->url('index.php?option=com_gitinsync&view=dashboard')->icon('icon-home');
		$toolbar->linkButton('history', 'COM_GITINSYNC_HISTORY')->url('index.php?option=com_gitinsync&view=history')->icon('icon-clock');
		$toolbar->linkButton('branches', 'COM_GITINSYNC_BRANCHES')->url('index.php?option=com_gitinsync&view=branches')->icon('icon-code-branch');

		if ($user?->authorise('core.admin', 'com_gitinsync') || $user?->authorise('core.options', 'com_gitinsync')) {
			ToolbarHelper::preferences('com_gitinsync');
		}
	}

	/**
	 * Runs a callable and keeps its error for the screen, instead of a blank page.
	 *
	 * @template T
	 *
	 * @param   callable(): T  $callable
	 * @param   T              $default
	 *
	 * @return  T
	 */
	protected function attempt(callable $callable, mixed $default): mixed
	{
		try {
			return $callable();
		} catch (\Throwable $e) {
			$this->error = $e->getMessage();

			return $default;
		}
	}
}

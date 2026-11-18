<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\View\Dashboard;

use Joomla\CMS\Language\Text;
use Yepr\Component\GitInSync\Administrator\Git\Commit;
use Yepr\Component\GitInSync\Administrator\Sync\OperationStore;
use Yepr\Component\GitInSync\Administrator\Sync\Settings;
use Yepr\Component\GitInSync\Administrator\View\BaseView;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Where the site stands: mode, branch, last commits, waiting changes, and the buttons.
 */
final class HtmlView extends BaseView
{
	public Settings $settings;

	public string $branch = '';

	public ?string $head = null;

	public ?string $githubHead = null;

	public int $dirty = 0;

	public ?int $openOperation = null;

	public ?bool $private = null;

	/**
	 * @var array<string, Commit>
	 */
	public array $commits = [];

	/**
	 * @var array<int, array{id: int, kind: string, status: string, branch: string, title: string, message: string, created: string}>
	 */
	public array $operations = [];

	/**
	 * @param   string|null  $tpl
	 */
	public function display($tpl = null): void
	{
		$services       = $this->services();
		$this->settings = $services->settings();
		$workspace      = $services->workspace();
		$this->branch   = $workspace->branch();
		$this->dirty    = $services->itemMap()->dirtyCount();

		$store               = $services->operationStore();
		$this->openOperation = $store->open();
		$this->operations    = $store->recent(5);

		$this->head    = $this->attempt(fn (): ?string => $workspace->head(), null);
		$this->commits = $this->head === null ? [] : $this->attempt(fn (): array => $workspace->repository()->log($this->head, 10), []);

		if ($this->settings->usesGitHub() && $this->settings->hasGitHubCredentials()) {
			$this->private = $this->attempt(fn (): bool => $workspace->isGitHubPrivate(), null);

			if (!$this->settings->usesLocal()) {
				$this->githubHead = $this->attempt(fn (): ?string => $workspace->branchHead(), null);
			}
		}

		$this->toolbar(Text::_('COM_GITINSYNC_DASHBOARD'));

		parent::display($tpl);
	}
}

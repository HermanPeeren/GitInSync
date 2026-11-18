<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\View\History;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Yepr\Component\GitInSync\Administrator\Git\Commit;
use Yepr\Component\GitInSync\Administrator\View\BaseView;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The commits of a branch, newest first.
 */
final class HtmlView extends BaseView
{
	public const PAGE = 30;

	public string $branch = '';

	public string $siteBranch = '';

	/**
	 * @var array<string, string>
	 */
	public array $branches = [];

	/**
	 * @var array<string, Commit>
	 */
	public array $commits = [];

	public int $start = 0;

	/**
	 * @param   string|null  $tpl
	 */
	public function display($tpl = null): void
	{
		$input            = Factory::getApplication()->getInput();
		$services         = $this->services();
		$workspace        = $services->workspace();
		$repository       = $workspace->repository();
		$this->siteBranch = $workspace->branch();
		$this->branch     = $input->getString('branch', '') ?: $this->siteBranch;
		$this->start      = max(0, $input->getInt('start', 0));
		$this->branches   = $this->attempt(fn (): array => $repository->branches(), []);

		$head = $this->branch === $this->siteBranch
			? $this->attempt(fn (): ?string => $workspace->head(), null)
			: ($this->branches[$this->branch] ?? null);

		$this->commits = $head === null ? [] : $this->attempt(fn (): array => $repository->log($head, self::PAGE, $this->start), []);

		$this->toolbar(Text::_('COM_GITINSYNC_HISTORY'));

		parent::display($tpl);
	}
}

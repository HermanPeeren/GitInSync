<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\View\Branches;

use Joomla\CMS\Language\Text;
use Yepr\Component\GitInSync\Administrator\Git\Commit;
use Yepr\Component\GitInSync\Administrator\View\BaseView;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The branches: create, switch, merge into the site's branch, delete.
 */
final class HtmlView extends BaseView
{
	public string $siteBranch = '';

	/**
	 * @var array<string, array{id: string, commit: ?Commit, merged: bool}>
	 */
	public array $branches = [];

	/**
	 * @param   string|null  $tpl
	 */
	public function display($tpl = null): void
	{
		$workspace        = $this->services()->workspace();
		$repository       = $workspace->repository();
		$this->siteBranch = $workspace->branch();
		$head             = $this->attempt(fn (): ?string => $workspace->head(), null);

		foreach ($this->attempt(fn (): array => $repository->branches(), []) as $name => $id) {
			$this->branches[$name] = [
				'id'     => $id,
				'commit' => $this->attempt(fn (): Commit => $repository->readCommit($id), null),
				'merged' => $head !== null && $this->attempt(fn (): bool => $repository->isAncestor($id, $head), false),
			];
		}

		$this->toolbar(Text::_('COM_GITINSYNC_BRANCHES'));

		parent::display($tpl);
	}
}

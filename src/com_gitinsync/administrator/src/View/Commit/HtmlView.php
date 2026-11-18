<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\View\Commit;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Yepr\Component\GitInSync\Administrator\Content\ItemPath;
use Yepr\Component\GitInSync\Administrator\Diff\LineDiff;
use Yepr\Component\GitInSync\Administrator\Git\Commit;
use Yepr\Component\GitInSync\Administrator\Git\GitObject;
use Yepr\Component\GitInSync\Administrator\View\BaseView;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One commit: what it changed, per item and file, with revert and cherry-pick.
 */
final class HtmlView extends BaseView
{
	public string $id = '';

	public ?Commit $commit = null;

	/**
	 * Whether the commit is in the site's branch (revert) or not (cherry-pick).
	 */
	public bool $onSiteBranch = false;

	/**
	 * @var array<string, array<string, array{status: string, diff: string}>>  Item key => file => change.
	 */
	public array $changes = [];

	/**
	 * @param   string|null  $tpl
	 */
	public function display($tpl = null): void
	{
		$this->id   = strtolower(Factory::getApplication()->getInput()->getString('id', ''));
		$workspace  = $this->services()->workspace();
		$repository = $workspace->repository();

		if (GitObject::isId($this->id)) {
			$this->commit = $this->attempt(fn (): Commit => $repository->readCommit($this->id), null);
		}

		if ($this->commit !== null) {
			$commit             = $this->commit;
			$head               = $this->attempt(fn (): ?string => $workspace->head(), null);
			$this->onSiteBranch = $head !== null && $this->attempt(fn (): bool => $repository->isAncestor($this->id, $head), false);

			$parentTree = isset($commit->parents[0]) ? $repository->readCommit($commit->parents[0])->tree : null;

			foreach ($this->attempt(fn (): array => $repository->changes($parentTree, $commit->tree), []) as $path => [$old, $new]) {
				$item = ItemPath::parse($path);
				$key  = $item === null ? $path : $item->key();
				$file = $item === null ? basename($path) : $item->file;

				$before = $old === null ? '' : $repository->readBlob($old);
				$after  = $new === null ? '' : $repository->readBlob($new);

				$this->changes[$key][$file] = [
					'status' => $old === null ? 'added' : ($new === null ? 'deleted' : 'modified'),
					'diff'   => LineDiff::unified($before, $after),
				];
			}
		}

		$this->toolbar(Text::_('COM_GITINSYNC_COMMIT'));

		parent::display($tpl);
	}
}

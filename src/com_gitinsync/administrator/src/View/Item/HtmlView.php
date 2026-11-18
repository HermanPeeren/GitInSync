<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\View\Item;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Yepr\Component\GitInSync\Administrator\Content\ContentType;
use Yepr\Component\GitInSync\Administrator\Content\ItemPath;
use Yepr\Component\GitInSync\Administrator\Diff\LineDiff;
use Yepr\Component\GitInSync\Administrator\Git\Commit;
use Yepr\Component\GitInSync\Administrator\View\BaseView;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The history of one item, its uncommitted changes, and restoring an older version.
 */
final class HtmlView extends BaseView
{
	public string $key = '';

	public ?ContentType $type = null;

	public ?int $localId = null;

	public bool $excluded = false;

	/**
	 * @var array<string, Commit>
	 */
	public array $commits = [];

	/**
	 * Uncommitted changes, per file.
	 *
	 * @var array<string, string>
	 */
	public array $pending = [];

	/**
	 * @param   string|null  $tpl
	 */
	public function display($tpl = null): void
	{
		$input    = Factory::getApplication()->getInput();
		$services = $this->services();
		$types    = $services->contentTypes();
		$map      = $services->itemMap();

		// Either the item's key, or its type and local id (from an edit screen).
		$this->key = $input->getString('key', '');

		if ($this->key === '' && ($type = $types->get($input->getCmd('type', ''))) !== null && $input->getInt('id') > 0) {
			$this->key = $type->directory() . '/' . $map->uuidFor($type->name(), $input->getInt('id'));
		}

		$path = ItemPath::parse($this->key . '/x');

		if ($path !== null) {
			$this->type = $types->byDirectory($path->directory);
		}

		if ($path !== null && $this->type !== null) {
			$type           = $this->type;
			$workspace      = $services->workspace();
			$repository     = $workspace->repository();
			$this->localId  = $map->idFor($type->name(), $path->uuid);
			$this->excluded = $this->localId !== null && $map->isExcluded($type->name(), $this->localId);
			$head           = $this->attempt(fn (): ?string => $workspace->head(), null);

			$key = function (Commit $commit) use ($repository, $path): ?string {
				return $repository->entryStartingWith($commit->tree, $path->directory, $path->uuid)[1] ?? null;
			};

			if ($head !== null) {
				$this->commits = $this->attempt(fn (): array => $repository->log($head, 50, 0, $key), []);
			}

			// What is in Joomla now against what was last committed.
			if ($this->localId !== null) {
				$committed = $head === null ? [] : (ItemPath::group($repository->files($head))[$this->key]['files'] ?? []);
				$now       = $type->export($this->localId)->files ?? [];

				foreach (array_unique(array_merge(array_keys($committed), array_keys($now))) as $file) {
					$before = isset($committed[$file]) ? $repository->readBlob($committed[$file]) : '';
					$after  = $now[$file] ?? '';

					if ($before !== $after) {
						$this->pending[$file] = LineDiff::unified($before, $after);
					}
				}
			}
		}

		$this->toolbar(Text::_('COM_GITINSYNC_ITEM_HISTORY'));

		parent::display($tpl);
	}
}

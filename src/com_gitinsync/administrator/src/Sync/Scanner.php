<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Sync;

use Yepr\Component\GitInSync\Administrator\Content\ContentTypes;
use Yepr\Component\GitInSync\Administrator\Content\ItemPath;
use Yepr\Component\GitInSync\Administrator\Job\Budget;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Compares every item with the branch and marks what differs, in portions.
 *
 * Batch actions, drag-and-drop ordering, imports and direct database changes don't
 * always fire content events; the scan finds them. On a new repository it is the first
 * export. Items are compared by blob id, so an unchanged item costs a read and a hash.
 */
final class Scanner
{
	private const PORTION = 50;

	public function __construct(
		private readonly Workspace $workspace,
		private readonly ContentTypes $types,
		private readonly ItemMap $map,
		private readonly Committer $committer,
		private readonly StateStore $state
	) {
	}

	/**
	 * @return  array{done: bool, marked: int, message: string}
	 */
	public function scan(Budget $budget): array
	{
		$cursor = json_decode($this->state->get('scan.cursor', '{}') ?? '{}', true);
		$cursor = \is_array($cursor) ? $cursor : [];
		$type   = (string) ($cursor['type'] ?? '');
		$after  = (int) ($cursor['after'] ?? 0);
		$marked = (int) ($cursor['marked'] ?? 0);

		$repository = $this->workspace->repository();
		$items      = ItemPath::group($repository->files($this->workspace->head()));
		$names      = array_keys($this->types->enabled());
		$start      = $type === '' ? 0 : (int) array_search($type, $names, true);

		for ($i = $start, $n = \count($names); $i < $n; $i++) {
			$contentType = $this->types->enabled()[$names[$i]];

			if ($names[$i] !== $type) {
				$after = 0;
			}

			while (true) {
				if ($budget->expired()) {
					$this->state->set('scan.cursor', (string) json_encode(['type' => $names[$i], 'after' => $after, 'marked' => $marked]));

					$message = \sprintf('Scanning %s, %d changes found so far.', $names[$i], $marked);

					return ['done' => false, 'marked' => $marked, 'message' => $message];
				}

				$ids = $contentType->ids($after, self::PORTION);

				if ($ids === []) {
					break;
				}

				foreach ($ids as $id) {
					$after    = $id;
					$expected = $this->committer->expected($contentType, $id);
					$key      = $contentType->directory() . '/' . $this->map->uuidFor($contentType->name(), $id);
					$current  = $items[$key] ?? null;

					if ($expected != $current) {
						$this->map->markDirty($contentType->name(), $id, ItemMap::DIRTY_SCANNED);
						$marked++;
					}
				}
			}
		}

		// Items in the branch whose local item is gone.
		foreach ($items as $key => $item) {
			$path = ItemPath::parse($key . '/x');

			if ($path === null) {
				continue;
			}

			$contentType = $this->types->byDirectory($path->directory);

			if ($contentType === null || !$this->types->isEnabled($contentType->name())) {
				continue;
			}

			$id = $this->map->idFor($contentType->name(), $path->uuid);

			if ($id !== null && $contentType->export($id) === null) {
				$this->map->markDirty($contentType->name(), $id, ItemMap::DIRTY_SCANNED);
				$marked++;
			}
		}

		$this->state->delete('scan.cursor');
		$this->state->set('scan.last', (string) time());

		return ['done' => true, 'marked' => $marked, 'message' => \sprintf('Scan done: %d changes found.', $marked)];
	}
}

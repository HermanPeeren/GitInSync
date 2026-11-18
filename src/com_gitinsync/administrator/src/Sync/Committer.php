<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Sync;

use Yepr\Component\GitInSync\Administrator\Content\ContentType;
use Yepr\Component\GitInSync\Administrator\Content\ContentTypes;
use Yepr\Component\GitInSync\Administrator\Content\ExportedItem;
use Yepr\Component\GitInSync\Administrator\Content\ItemPath;
use Yepr\Component\GitInSync\Administrator\Git\GitObject;
use Yepr\Component\GitInSync\Administrator\Git\RefConflict;
use Yepr\Component\GitInSync\Administrator\Git\Repository;
use Yepr\Component\GitInSync\Administrator\Job\Budget;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns changed items into commits on the site's branch.
 *
 * An item saved in Joomla gets a commit of its own, with the editor as author and the
 * time of the change. Items found by the scan (changes that fired no event, or the first
 * export) are committed together, a portion at a time.
 */
final class Committer
{
	/**
	 * Items per commit when committing what the scan found.
	 */
	private const BATCH = 100;

	private ?bool $mayStoreNonPublic = null;

	public function __construct(
		private readonly Workspace $workspace,
		private readonly ContentTypes $types,
		private readonly ItemMap $map,
		private readonly Identity $identity,
		private readonly StateStore $state
	) {
	}

	/**
	 * @return  array{done: bool, commits: int, message: string}
	 */
	public function commitDirty(Budget $budget): array
	{
		if ($this->state->get('operation.active') !== null) {
			return ['done' => true, 'commits' => 0, 'message' => 'An operation is being applied; changes are committed after it.'];
		}

		if (!$this->state->lock('commit', 300)) {
			return ['done' => false, 'commits' => 0, 'message' => 'Another run is committing.'];
		}

		try {
			return $this->commitDirtyLocked($budget);
		} finally {
			$this->state->unlock('commit');
		}
	}

	/**
	 * @return  array{done: bool, commits: int, message: string}
	 */
	private function commitDirtyLocked(Budget $budget): array
	{
		$repository = $this->workspace->repository();
		$head       = $this->workspace->head();
		$items      = ItemPath::group($repository->files($head));
		$commits    = 0;

		while (!$budget->expired()) {
			$dirty = $this->map->dirty(self::BATCH);

			if ($dirty === []) {
				return ['done' => true, 'commits' => $commits, 'message' => \sprintf('%d commits.', $commits)];
			}

			// A saved item on its own; scanned items together.
			$batch = $dirty[0]['dirty'] === ItemMap::DIRTY_SAVED ? [$dirty[0]] : array_filter(
				$dirty,
				static fn (array $row): bool => $row['dirty'] === ItemMap::DIRTY_SCANNED
			);

			$changes  = [];
			$authorId = 0;
			$date     = null;

			foreach ($batch as $row) {
				$type = $this->types->get($row['type']);

				if ($type === null || !$this->types->isEnabled($row['type'])) {
					continue;
				}

				$change = $this->applyItem($repository, $items, $type, $row['id'], $row['uuid']);

				if ($change !== null) {
					$changes[] = $change;
					$authorId  = $change['author'];
					$date      = $change['date'];
				}
			}

			$clean = array_map(static fn (array $row): array => ['type' => $row['type'], 'id' => $row['id']], $batch);

			if ($changes === []) {
				$this->map->markClean($clean);

				continue;
			}

			$single  = \count($batch) === 1 && $batch[0]['dirty'] === ItemMap::DIRTY_SAVED;
			$message = $single ? $this->message($changes[0]) : $this->batchMessage($changes);
			$time    = $single && $date !== null ? $this->timestamp($date) : time();
			$author  = $single ? $this->identity->author($authorId, $time) : $this->identity->committer();

			$tree    = $repository->writeTree(ItemPath::ungroup($items));
			$commit  = $repository->writeCommit($tree, $head === null ? [] : [$head], $author, $this->identity->committer(), $message);

			try {
				$this->workspace->moveHead($commit, $head);
			} catch (RefConflict $e) {
				return ['done' => false, 'commits' => $commits, 'message' => $e->getMessage()];
			}

			$head = $commit;
			$commits++;
			$this->map->markClean($clean);
		}

		return ['done' => false, 'commits' => $commits, 'message' => \sprintf('%d commits, more to go.', $commits)];
	}

	/**
	 * Puts the item's current state into the grouped files.
	 *
	 * @param   array<string, array{folder: string, files: array<string, string>}>  $items
	 *
	 * @return  array{action: string, type: string, title: string, author: int, date: ?string}|null  Null when nothing changed.
	 */
	public function applyItem(Repository $repository, array &$items, ContentType $type, int $id, string $uuid): ?array
	{
		$key      = $type->directory() . '/' . $uuid;
		$exported = $type->export($id);
		$present  = isset($items[$key]);

		if ($exported === null || !$this->storable($exported)) {
			if (!$present) {
				return null;
			}

			$title = $this->titleOf($repository, $items[$key], $type);
			unset($items[$key]);

			return ['action' => 'remove', 'type' => $type->name(), 'title' => $title, 'author' => $exported->authorId ?? 0, 'date' => null];
		}

		$files = [];

		foreach ($exported->files as $name => $content) {
			$files[$name] = $repository->writeBlob($content);
		}

		ksort($files);
		$new = ['folder' => $exported->folder, 'files' => $files];

		if ($present && $items[$key] == $new) {
			return null;
		}

		$items[$key] = $new;

		return [
			'action' => $present ? 'update' : 'add',
			'type'   => $type->name(),
			'title'  => $exported->title,
			'author' => $exported->authorId,
			'date'   => $exported->modified,
		];
	}

	/**
	 * Whether the item belongs in the repository: not excluded, not trashed (unless that is
	 * wanted), and public unless non-public content may be stored.
	 */
	public function storable(ExportedItem $item): bool
	{
		if ($this->map->isExcluded($item->type, $item->id)) {
			return false;
		}

		if ($item->trashed) {
			return $this->workspace->settings()->includeTrashed() && $this->mayStoreNonPublic();
		}

		if (!$item->published || !$item->public) {
			return $this->mayStoreNonPublic();
		}

		return true;
	}

	/**
	 * The blob ids an item would have, without writing anything: for the scan.
	 *
	 * @return  array{folder: string, files: array<string, string>}|null  Null when it doesn't belong in the repository.
	 */
	public function expected(ContentType $type, int $id): ?array
	{
		$exported = $type->export($id);

		if ($exported === null || !$this->storable($exported)) {
			return null;
		}

		$files = [];

		foreach ($exported->files as $name => $content) {
			$files[$name] = GitObject::blob($content)->id();
		}

		ksort($files);

		return ['folder' => $exported->folder, 'files' => $files];
	}

	private function mayStoreNonPublic(): bool
	{
		return $this->mayStoreNonPublic ??= $this->workspace->mayStoreNonPublic();
	}

	/**
	 * @param   array{action: string, type: string, title: string, author: int, date: ?string}  $change
	 */
	private function message(array $change): string
	{
		$verb = match ($change['action']) {
			'add'    => 'Add',
			'remove' => 'Remove',
			default  => 'Update',
		};

		return \sprintf('%s %s "%s"', $verb, $change['type'], $change['title']);
	}

	/**
	 * @param   array<int, array{action: string, type: string, title: string, author: int, date: ?string}>  $changes
	 */
	private function batchMessage(array $changes): string
	{
		$lines = array_map(fn (array $change): string => '- ' . $this->message($change), \array_slice($changes, 0, 50));

		if (\count($changes) > 50) {
			$lines[] = \sprintf('- and %d more', \count($changes) - 50);
		}

		return \sprintf("GitInSync: %d items\n\n%s", \count($changes), implode("\n", $lines));
	}

	/**
	 * @param   array{folder: string, files: array<string, string>}  $item
	 */
	private function titleOf(Repository $repository, array $item, ContentType $type): string
	{
		$main = $item['files'][$type->mainFile()] ?? null;

		if ($main === null) {
			return basename($item['folder']);
		}

		$data = json_decode($repository->readBlob($main), true);

		return \is_array($data) ? (string) ($data['title'] ?? $data['name'] ?? basename($item['folder'])) : basename($item['folder']);
	}

	/**
	 * Joomla stores dates in UTC.
	 */
	private function timestamp(string $date): int
	{
		try {
			return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp();
		} catch (\Exception) {
			return time();
		}
	}
}

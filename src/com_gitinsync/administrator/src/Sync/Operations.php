<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Sync;

use Joomla\CMS\User\User;
use Yepr\Component\GitInSync\Administrator\Content\ApplyFailed;
use Yepr\Component\GitInSync\Administrator\Content\CanonicalJson;
use Yepr\Component\GitInSync\Administrator\Content\ContentTypes;
use Yepr\Component\GitInSync\Administrator\Content\ItemPath;
use Yepr\Component\GitInSync\Administrator\Git\GitException;
use Yepr\Component\GitInSync\Administrator\Git\RefConflict;
use Yepr\Component\GitInSync\Administrator\Git\Repository;
use Yepr\Component\GitInSync\Administrator\Git\Signature;
use Yepr\Component\GitInSync\Administrator\Job\Budget;
use Yepr\Component\GitInSync\Administrator\Merge\Conflict;
use Yepr\Component\GitInSync\Administrator\Merge\TreeMerge;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Restore, revert, cherry-pick, merge, pull and switching branches.
 *
 * Each computes the files the site should end up with (a three-way merge where needed),
 * waits for the user to resolve any conflicts, then writes the changed items into Joomla
 * through its models, a portion per run, and finally commits. An item that can't be
 * written (no permission, checked out) keeps its current version in that commit and is
 * reported: git always says what the site has.
 */
final class Operations
{
	public function __construct(
		private readonly Workspace $workspace,
		private readonly ContentTypes $types,
		private readonly ItemMap $map,
		private readonly Identity $identity,
		private readonly StateStore $state,
		private readonly OperationStore $store,
		private readonly Committer $committer
	) {
	}

	public function store(): OperationStore
	{
		return $this->store;
	}

	/**
	 * Puts one item back as it was in a commit.
	 */
	public function restore(string $key, string $commitId, User $user): int
	{
		$repository = $this->workspace->repository();
		[$ours, $files] = $this->prepare($user);

		$target = ItemPath::group($files);
		$then   = ItemPath::group($repository->files($commitId))[$key] ?? null;

		if ($then === null) {
			unset($target[$key]);
		} else {
			$target[$key] = $then;
		}

		$title = $this->itemTitle($repository, $then ?? (ItemPath::group($files)[$key] ?? null), $key);

		return $this->create('restore', $ours, [
			'parents' => $ours === null ? [] : [$ours],
			'message' => \sprintf('Restore %s as of %s', $title, substr($commitId, 0, 7)),
			'target'  => ItemPath::ungroup($target),
		], [], $user, \sprintf('Restore %s', $title));
	}

	/**
	 * Undoes the changes of one commit, keeping what came after it.
	 */
	public function revert(string $commitId, User $user): int
	{
		$repository     = $this->workspace->repository();
		[$ours, $files] = $this->prepare($user);
		$commit         = $repository->readCommit($commitId);
		$parent         = $commit->parents[0] ?? null;

		$result = (new TreeMerge($repository))->merge($repository->files($commitId), $files, $repository->files($parent), 'current', 'reverted');

		return $this->create('revert', $ours, [
			'parents' => $ours === null ? [] : [$ours],
			'message' => \sprintf("Revert \"%s\"\n\nThis reverts commit %s.", $commit->subject(), $commitId),
			'target'  => $result->files,
		], $result->conflicts, $user, \sprintf('Revert "%s"', $commit->subject()));
	}

	/**
	 * Takes over the changes of one commit, from any branch.
	 */
	public function cherryPick(string $commitId, User $user): int
	{
		$repository     = $this->workspace->repository();
		[$ours, $files] = $this->prepare($user);
		$commit         = $repository->readCommit($commitId);
		$parent         = $commit->parents[0] ?? null;

		$result = (new TreeMerge($repository))->merge($repository->files($parent), $files, $repository->files($commitId), 'current', 'picked');

		return $this->create('cherrypick', $ours, [
			'parents' => $ours === null ? [] : [$ours],
			'message' => rtrim($commit->message) . "\n\n(cherry picked from commit " . $commitId . ')',
			'author'  => [$commit->author->name, $commit->author->email, $commit->author->timestamp, $commit->author->offset],
			'target'  => $result->files,
		], $result->conflicts, $user, \sprintf('Cherry-pick "%s"', $commit->subject()));
	}

	/**
	 * Merges a commit (the head of another branch, or of the branch on GitHub) into the
	 * site's branch. Returns 0 when there is nothing to merge.
	 */
	public function merge(string $theirs, string $label, User $user): int
	{
		$repository     = $this->workspace->repository();
		[$ours, $files] = $this->prepare($user);

		if ($ours === $theirs || ($ours !== null && $repository->isAncestor($theirs, $ours))) {
			return 0;
		}

		$base = $ours === null ? null : $repository->mergeBase($ours, $theirs);

		if ($base === $ours) {
			return $this->create('fastforward', $ours, [
				'final'  => $theirs,
				'target' => $repository->files($theirs),
			], [], $user, \sprintf('Update to %s', $label));
		}

		$result = (new TreeMerge($repository))
			->merge($repository->files($base), $files, $repository->files($theirs), $this->workspace->branch(), $label);

		return $this->create('merge', $ours, [
			'parents' => [$ours, $theirs],
			'message' => \sprintf("Merge %s into %s", $label, $this->workspace->branch()),
			'target'  => $result->files,
		], $result->conflicts, $user, \sprintf('Merge %s', $label));
	}

	/**
	 * Fetches the site's branch from GitHub (in "local + GitHub" mode) and merges it; in
	 * GitHub-only mode merges what the branch on GitHub has moved on to.
	 *
	 * @return  array{done: bool, operation: int, message: string}
	 */
	public function pull(User $user, Budget $budget): array
	{
		$branch   = $this->workspace->branch();
		$transfer = $this->workspace->transfer();

		if ($transfer !== null) {
			$fetch = $transfer->fetch($branch, $budget);

			if (!$fetch['done'] || $fetch['head'] === null) {
				return ['done' => $fetch['done'], 'operation' => 0, 'message' => $fetch['message']];
			}

			$theirs = $fetch['head'];
		} elseif (!$this->workspace->settings()->usesLocal()) {
			$theirs = $this->workspace->branchHead();

			if ($theirs === null) {
				return ['done' => true, 'operation' => 0, 'message' => 'The branch is not on GitHub yet.'];
			}
		} else {
			return ['done' => true, 'operation' => 0, 'message' => 'There is no GitHub repository to pull from.'];
		}

		$operation = $this->merge($theirs, 'origin/' . $branch, $user);

		return [
			'done'      => true,
			'operation' => $operation,
			'message'   => $operation === 0 ? 'Already up to date.' : 'Changes from GitHub are being applied.',
		];
	}

	/**
	 * Makes the site follow another branch: its content becomes that branch's content.
	 */
	public function checkout(string $branch, User $user): int
	{
		$repository     = $this->workspace->repository();
		[$ours]         = $this->prepare($user);
		$theirs         = $repository->head($branch);

		if ($theirs === null) {
			throw new GitException(\sprintf('There is no branch %s.', $branch));
		}

		return $this->create('checkout', $ours, [
			'to'     => $branch,
			'final'  => $theirs,
			'target' => $repository->files($theirs),
		], [], $user, \sprintf('Switch to %s', $branch));
	}

	public function createBranch(string $name, ?string $from): void
	{
		$name = trim($name);

		if ($name === '' || !preg_match('#^[A-Za-z0-9._/-]+$#', $name) || str_contains($name, '..') || str_ends_with($name, '.lock')) {
			throw new GitException('A branch name can have letters, digits, ".", "_", "-" and "/".');
		}

		$from ??= $this->workspace->head();

		if ($from === null) {
			throw new GitException('There is nothing to branch from yet: commit something first.');
		}

		$this->workspace->repository()->refs->set(Repository::branchRef($name), $from, null);
	}

	public function deleteBranch(string $name): void
	{
		if ($name === $this->workspace->branch()) {
			throw new GitException('The site follows this branch; switch to another branch first.');
		}

		$this->workspace->repository()->refs->delete(Repository::branchRef($name));
	}

	/**
	 * Settles the conflicts of an operation.
	 *
	 * @param   array<int, array{choice?: string, files?: array<string, string>}>  $choices  Per conflict (by index):
	 *          "ours", "theirs", "base" (only for deletions: delete), or "edit" with the files' texts.
	 */
	public function resolve(int $id, array $choices, User $user): void
	{
		$operation = $this->store->get($id) ?? throw new GitException('No such operation.');

		if ($operation['status'] !== OperationStore::CONFLICTS) {
			throw new GitException('This operation has no open conflicts.');
		}

		$repository = $this->workspace->repository();
		$data       = $operation['data'];
		$target     = ItemPath::group((array) $data['target']);

		foreach ((array) $data['conflicts'] as $index => $raw) {
			$conflict = Conflict::fromArray((array) $raw);
			$choice   = $choices[$index]['choice'] ?? '';

			$item = match ($choice) {
				'ours'   => $conflict->ours,
				'theirs' => $conflict->theirs,
				'delete' => null,
				'edit'   => $this->edited($repository, $conflict, $choices[$index]['files'] ?? []),
				default  => throw new GitException(\sprintf('Choose what to keep for %s.', $conflict->key)),
			};

			if ($item === null) {
				unset($target[$conflict->key]);
			} else {
				$target[$conflict->key] = $item;
			}
		}

		$data['target']    = ItemPath::ungroup($target);
		$data['resolved']  = $data['conflicts'];
		$data['conflicts'] = [];

		$this->startApplying($id, $data);
	}

	/**
	 * Applies a portion of an operation.
	 *
	 * @return  array{done: bool, status: string, message: string, applied: int, pending: int}
	 */
	public function step(int $id, Budget $budget, User $user): array
	{
		$operation = $this->store->get($id) ?? throw new GitException('No such operation.');

		if ($operation['status'] !== OperationStore::APPLYING) {
			return ['done' => true, 'status' => $operation['status'], 'message' => $operation['message'], 'applied' => 0, 'pending' => 0];
		}

		$repository = $this->workspace->repository();
		$data       = $operation['data'];
		$target     = ItemPath::group((array) $data['target']);
		$pending    = (array) ($data['pending'] ?? []);
		$applied    = (array) ($data['applied'] ?? []);
		$failed     = (array) ($data['failed'] ?? []);

		while ($pending !== [] && !$budget->expired()) {
			$key  = (string) array_shift($pending);
			$path = ItemPath::parse($key . '/x');
			$type = $path === null ? null : $this->types->byDirectory($path->directory);

			if ($path === null || $type === null) {
				continue;
			}

			$localId = $this->map->idFor($type->name(), $path->uuid);

			try {
				if (!isset($target[$key])) {
					if ($localId !== null) {
						$type->remove($localId, $user);
					}
				} else {
					$files = [];

					foreach ($target[$key]['files'] as $name => $blob) {
						$files[$name] = $repository->readBlob($blob);
					}

					$localId = $type->import($files, $localId, $user);
				}

				if ($localId !== null) {
					// Saving fired a content event; this item goes into the operation's commit.
					$this->map->markClean([['type' => $type->name(), 'id' => $localId]]);
				}

				$applied[] = $key;
			} catch (ApplyFailed $e) {
				$failed[$key] = $e->getMessage();
			} catch (\RuntimeException $e) {
				$failed[$key] = $e->getMessage();
			}
		}

		$data['pending'] = $pending;
		$data['applied'] = $applied;
		$data['failed']  = $failed;

		if ($pending !== []) {
			$this->store->update($id, OperationStore::APPLYING, $data);

			return [
				'done'    => false,
				'status'  => OperationStore::APPLYING,
				'message' => '',
				'applied' => \count($applied),
				'pending' => \count($pending),
			];
		}

		return $this->finish($id, $operation['kind'], $data, $user);
	}

	public function abort(int $id): void
	{
		$operation = $this->store->get($id) ?? throw new GitException('No such operation.');

		if (!\in_array($operation['status'], [OperationStore::CONFLICTS, OperationStore::APPLYING], true)) {
			return;
		}

		$message = $operation['status'] === OperationStore::APPLYING
			? 'Aborted while applying: items already written stay as they are and are committed as changes.'
			: 'Aborted.';

		$this->store->update($id, OperationStore::ABORTED, null, $message);
		$this->state->delete('operation.active');
	}

	/**
	 * @param   array<string, mixed>  $data
	 *
	 * @return  array{done: bool, status: string, message: string, applied: int, pending: int}
	 */
	private function finish(int $id, string $kind, array $data, User $user): array
	{
		$repository = $this->workspace->repository();
		$ours       = isset($data['ours']) ? (string) $data['ours'] : null;
		$failed     = (array) $data['failed'];
		$applied    = \count((array) $data['applied']);

		try {
			if ($this->workspace->head() !== $ours) {
				throw new RefConflict('The branch moved while this was being applied.');
			}

			// What the site now has: the target, except items that couldn't be written.
			$target = ItemPath::group((array) $data['target']);
			$before = ItemPath::group($repository->files($ours));

			foreach (array_keys($failed) as $key) {
				if (isset($before[$key])) {
					$target[$key] = $before[$key];
				} else {
					unset($target[$key]);
				}
			}

			if ($kind === 'checkout') {
				$this->workspace->setBranch((string) $data['to']);
			} elseif (($kind === 'fastforward') && $failed === []) {
				$this->workspace->moveHead((string) $data['final'], $ours);
			} else {
				$tree = $repository->writeTree(ItemPath::ungroup($target));

				if ($kind === 'fastforward') {
					$data['parents'] = [$ours, (string) $data['final']];
					$data['message'] = 'Update to ' . substr((string) $data['final'], 0, 7) . ', except items that could not be written';
				}

				$parents = array_values(array_filter(array_map('strval', (array) ($data['parents'] ?? []))));

				if ($ours === null || $tree !== $repository->readCommit($ours)->tree || \count($parents) > 1) {
					$author = isset($data['author']) && \is_array($data['author'])
						? new Signature(
							(string) $data['author'][0],
							(string) $data['author'][1],
							(int) $data['author'][2],
							(string) $data['author'][3]
						)
						: $this->identity->author((int) $user->id);

					$commit = $repository->writeCommit($tree, $parents, $author, $this->identity->committer(), (string) $data['message']);
					$this->workspace->moveHead($commit, $ours);
				}
			}
		} catch (\RuntimeException $e) {
			$this->store->update($id, OperationStore::FAILED, $data, $e->getMessage());
			$this->state->delete('operation.active');

			return ['done' => true, 'status' => OperationStore::FAILED, 'message' => $e->getMessage(), 'applied' => $applied, 'pending' => 0];
		}

		$message = $failed === []
			? \sprintf('%d items written.', $applied)
			: \sprintf('%d items written, %d could not be: %s', $applied, \count($failed), implode('; ', array_map(
				static fn (string $key, string $why): string => $key . ': ' . $why,
				array_keys($failed),
				$failed
			)));

		$this->store->update($id, OperationStore::DONE, $data, $message);
		$this->state->delete('operation.active');

		return ['done' => true, 'status' => OperationStore::DONE, 'message' => $message, 'applied' => $applied, 'pending' => 0];
	}

	/**
	 * Commits what is waiting first, so the operation starts from the site's content, and
	 * refuses to start a second operation.
	 *
	 * @return  array{0: ?string, 1: array<string, string>}  The site's commit and its files.
	 */
	private function prepare(User $user): array
	{
		$open = $this->store->open();

		if ($open !== null) {
			throw new GitException(\sprintf('Operation %d is not finished yet. Finish or abort it first.', $open));
		}

		$this->committer->commitDirty(Budget::unlimited());

		if ($this->map->dirtyCount() > 0) {
			throw new GitException('Not all changes could be committed. Commit them first.');
		}

		$head = $this->workspace->head();

		return [$head, $this->workspace->repository()->files($head)];
	}

	/**
	 * @param   array<string, mixed>  $data
	 * @param   Conflict[]            $conflicts
	 */
	private function create(string $kind, ?string $ours, array $data, array $conflicts, User $user, string $title): int
	{
		$data['ours']      = $ours;
		$data['conflicts'] = array_map(static fn (Conflict $c): array => $c->toArray(), $conflicts);
		$data['applied']   = [];
		$data['failed']    = [];

		$id = $this->store->insert($kind, OperationStore::CONFLICTS, $this->workspace->branch(), $title, $data, (int) $user->id);

		if ($conflicts === []) {
			$this->startApplying($id, $data);
		}

		return $id;
	}

	/**
	 * @param   array<string, mixed>  $data
	 */
	private function startApplying(int $id, array $data): void
	{
		$repository      = $this->workspace->repository();
		$ours            = isset($data['ours']) ? (string) $data['ours'] : null;
		$data['pending'] = $this->plan($repository, $repository->files($ours), (array) $data['target']);

		$this->store->update($id, OperationStore::APPLYING, $data);
		$this->state->set('operation.active', (string) $id);
	}

	/**
	 * The items to write, in an order that works: types in their order, parents before
	 * children; removals last, children first.
	 *
	 * @param   array<string, string>  $from
	 * @param   array<string, string>  $to
	 *
	 * @return  string[]
	 */
	private function plan(Repository $repository, array $from, array $to): array
	{
		$before = ItemPath::group($from);
		$after  = ItemPath::group($to);

		$imports  = [];
		$removals = [];

		foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
			if (($before[$key] ?? null) == ($after[$key] ?? null) || ItemPath::parse($key . '/x') === null) {
				continue;
			}

			if (isset($after[$key])) {
				$imports[] = $key;
			} else {
				$removals[] = $key;
			}
		}

		$parents = [];

		foreach ($imports as $key) {
			$type = $this->types->byDirectory(explode('/', $key)[0]);
			$main = $type === null ? null : ($after[$key]['files'][$type->mainFile()] ?? null);

			if ($type !== null && $main !== null && $type->parentKey() !== null) {
				$json  = CanonicalJson::decode($repository->readBlob($main));
				$uuid  = $json[$type->parentKey()] ?? null;
				$parents[$key] = \is_string($uuid) ? $type->directory() . '/' . $uuid : null;
			}
		}

		$depth = function (string $key, int $guard = 0) use (&$depth, $parents): int {
			$parent = $parents[$key] ?? null;

			return $parent === null || !isset($parents[$parent]) || $guard > 50 ? 0 : 1 + $depth($parent, $guard + 1);
		};

		$sort = function (string $a, string $b) use ($depth): int {
			$typeA = $this->types->byDirectory(explode('/', $a)[0]);
			$typeB = $this->types->byDirectory(explode('/', $b)[0]);

			return [$this->types->order($typeA?->name() ?? ''), $depth($a)] <=> [$this->types->order($typeB?->name() ?? ''), $depth($b)];
		};

		usort($imports, $sort);
		usort($removals, $sort);

		return array_merge($imports, array_reverse($removals));
	}

	/**
	 * @param   array<string, string>  $texts  File name => edited text.
	 *
	 * @return  array{folder: string, files: array<string, string>}
	 */
	private function edited(Repository $repository, Conflict $conflict, array $texts): array
	{
		$item = $conflict->ours ?? $conflict->theirs ?? throw new GitException('Nothing to edit.');

		foreach ($texts as $file => $text) {
			if (!isset($item['files'][$file]) && !isset($conflict->theirs['files'][$file])) {
				continue;
			}

			$text = str_replace("\r\n", "\n", $text);

			if (str_ends_with($file, '.json')) {
				// Must still be JSON; written canonically.
				$text = CanonicalJson::encode(CanonicalJson::decode($text));
			}

			$item['files'][$file] = $repository->writeBlob($text);
		}

		return $item;
	}

	/**
	 * @param   array{folder: string, files: array<string, string>}|null  $item
	 */
	private function itemTitle(Repository $repository, ?array $item, string $key): string
	{
		if ($item === null) {
			return $key;
		}

		$type = $this->types->byDirectory(explode('/', $key)[0]);
		$main = $type === null ? null : ($item['files'][$type->mainFile()] ?? null);

		if ($main === null || $type === null) {
			return $key;
		}

		$json = CanonicalJson::decode($repository->readBlob($main));

		return \sprintf('%s "%s"', $type->name(), (string) ($json['title'] ?? $json['name'] ?? $key));
	}
}

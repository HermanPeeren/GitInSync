<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Remote;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Yepr\Component\GitInSync\Administrator\Git\Commit;
use Yepr\Component\GitInSync\Administrator\Git\GitException;
use Yepr\Component\GitInSync\Administrator\Git\GitObject;
use Yepr\Component\GitInSync\Administrator\Git\LocalRepository;
use Yepr\Component\GitInSync\Administrator\Git\Repository;
use Yepr\Component\GitInSync\Administrator\Git\Tree;
use Yepr\Component\GitInSync\Administrator\Job\Budget;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Push and fetch between the local repository and GitHub, without git's own protocol.
 *
 * Push creates each missing object again through the API, oldest commit first, and moves
 * GitHub's branch forward after every commit. A run that stops halfway leaves GitHub with
 * part of the history, and the next run goes on from there. Objects uploaded but not yet
 * part of a pushed commit are remembered in #__gitinsync_pushed, so a large commit is
 * uploaded over several runs.
 *
 * Fetch does the opposite and puts GitHub's branch in refs/remotes/origin/<branch>.
 */
final class Transfer
{
	private const PUSHED = '#__gitinsync_pushed';

	private const OBJECTS = '#__gitinsync_objects';

	/**
	 * Objects moved in this run. A run always moves at least one before it looks at the
	 * budget, so even a tight budget gets the work done, a run at a time.
	 */
	private int $moved = 0;

	public function __construct(
		private readonly LocalRepository $local,
		private readonly Repository $repository,
		private readonly GitHubObjects $remote,
		private readonly DatabaseInterface $db
	) {
	}

	public static function remoteRef(string $branch): string
	{
		return 'refs/remotes/origin/' . $branch;
	}

	/**
	 * @return  array{done: bool, commits: int, message: string}
	 */
	public function push(string $branch, Budget $budget): array
	{
		$client = $this->remote->client();
		$local  = $this->repository->head($branch);

		if ($local === null) {
			return ['done' => true, 'commits' => 0, 'message' => 'Nothing to push: the branch has no commits yet.'];
		}

		$remote = $client->getRef($branch);

		if ($remote === $local) {
			return ['done' => true, 'commits' => 0, 'message' => 'GitHub is up to date.'];
		}

		if ($remote !== null && (!$this->local->has($remote) || !$this->repository->isAncestor($remote, $local))) {
			throw new GitException('GitHub has commits on this branch that this site doesn\'t have. Pull first, then push.');
		}

		// The commits GitHub doesn't have, oldest first.
		$missing = [];
		$stack   = [$local];
		$seen    = [];

		while ($stack !== []) {
			$id = array_pop($stack);

			if (isset($seen[$id]) || ($remote !== null && $this->repository->isAncestor($id, $remote)) || $this->isPushed($id)) {
				continue;
			}

			$seen[$id] = true;
			$missing[] = $id;

			foreach ($this->repository->readCommit($id)->parents as $parent) {
				$stack[] = $parent;
			}
		}

		usort($missing, fn (string $a, string $b): int => $this->depth($a) <=> $this->depth($b));

		$pushed      = 0;
		$this->moved = 0;

		foreach ($missing as $id) {
			if ($this->moved > 0 && $budget->expired()) {
				return ['done' => false, 'commits' => $pushed, 'message' => \sprintf('%d commits pushed, more to go.', $pushed)];
			}

			$commit = $this->repository->readCommit($id);
			$parent = $commit->parents[0] ?? null;

			if (!$this->uploadTree($commit->tree, $parent === null ? null : $this->repository->readCommit($parent)->tree, $budget)) {
				return ['done' => false, 'commits' => $pushed, 'message' => \sprintf('%d commits pushed; a large one is being uploaded.', $pushed)];
			}

			$this->remote->create($this->local->read($id, GitObject::COMMIT));
			$this->markPushed($id);
			$this->moved++;

			// Move GitHub's branch along, when this commit is on its line.
			$remoteNow = $client->getRef($branch);

			if ($remoteNow === null) {
				$client->createRef($branch, $id);
			} elseif ($this->repository->isAncestor($remoteNow, $id)) {
				$client->updateRef($branch, $id);
			}

			$this->local->set(self::remoteRef($branch), $id);
			$pushed++;
		}

		$this->forgetPushed();

		return ['done' => true, 'commits' => $pushed, 'message' => \sprintf('%d commits pushed.', $pushed)];
	}

	/**
	 * @return  array{done: bool, commits: int, message: string, head: ?string}
	 */
	public function fetch(string $branch, Budget $budget): array
	{
		$remote = $this->remote->client()->getRef($branch);

		if ($remote === null) {
			return ['done' => true, 'commits' => 0, 'message' => 'The branch is not on GitHub.', 'head' => null];
		}

		// The commits this site doesn't have, read from GitHub newest first.
		$missing = [];
		$stack   = [$remote];
		$read    = 0;

		while ($stack !== []) {
			$id = array_pop($stack);

			if (isset($missing[$id]) || $this->local->has($id)) {
				continue;
			}

			[$object, $fresh] = $this->commitFromGitHub($id);
			$missing[$id]      = $object;
			$read             += $fresh ? 1 : 0;

			foreach (Commit::fromObject($object)->parents as $parent) {
				$stack[] = $parent;
			}

			if ($read > 0 && $budget->expired()) {
				return ['done' => false, 'commits' => 0, 'message' => 'Reading GitHub\'s history, more to go.', 'head' => null];
			}
		}

		// Oldest first, each with its whole tree before the commit itself is written: a
		// commit that is here always has its content here too.
		$fetched     = 0;
		$this->moved = 0;

		foreach (array_reverse($missing, true) as $id => $object) {
			if (!$this->downloadTree(Commit::fromObject($object)->tree, $budget)) {
				return ['done' => false, 'commits' => $fetched, 'message' => \sprintf('%d commits fetched, more to go.', $fetched), 'head' => null];
			}

			$this->local->writeAs($id, $object);
			$this->forgetCommits([$id]);
			$fetched++;
		}

		$this->local->set(self::remoteRef($branch), $remote);

		return ['done' => true, 'commits' => $fetched, 'message' => \sprintf('%d commits fetched.', $fetched), 'head' => $remote];
	}

	/**
	 * Uploads a tree and what it holds, skipping what its parent commit's tree had.
	 */
	private function uploadTree(string $tree, ?string $parentTree, Budget $budget): bool
	{
		if ($tree === $parentTree || $this->isPushed($tree)) {
			return true;
		}

		$parentEntries = $parentTree === null ? [] : $this->repository->readTree($parentTree)->entries();

		foreach ($this->repository->readTree($tree)->entries() as $name => $entry) {
			$before = $parentEntries[$name] ?? null;

			if ($before !== null && $before['id'] === $entry['id']) {
				continue;
			}

			if ($entry['mode'] === Tree::MODE_TREE) {
				$beforeTree = $before !== null && $before['mode'] === Tree::MODE_TREE ? $before['id'] : null;

				if (!$this->uploadTree($entry['id'], $beforeTree, $budget)) {
					return false;
				}
			} elseif ($entry['mode'] !== Tree::MODE_SUBMODULE && !$this->isPushed($entry['id'])) {
				if ($this->moved > 0 && $budget->expired()) {
					return false;
				}

				$this->remote->create($this->local->read($entry['id'], GitObject::BLOB));
				$this->markPushed($entry['id']);
				$this->moved++;
			}
		}

		$this->remote->create($this->local->read($tree, GitObject::TREE));
		$this->markPushed($tree);

		return true;
	}

	private function downloadTree(string $tree, Budget $budget): bool
	{
		if ($this->local->has($tree)) {
			return true;
		}

		$object = $this->remote->fetch($tree, GitObject::TREE);

		foreach (Tree::fromObject($object)->entries() as $entry) {
			if ($entry['mode'] === Tree::MODE_TREE) {
				if (!$this->downloadTree($entry['id'], $budget)) {
					return false;
				}
			} elseif ($entry['mode'] !== Tree::MODE_SUBMODULE && !$this->local->has($entry['id'])) {
				if ($this->moved > 0 && $budget->expired()) {
					return false;
				}

				$this->local->write($this->remote->fetch($entry['id'], GitObject::BLOB));
				$this->moved++;
			}
		}

		// The tree last: a tree that is here has all its content here.
		$this->local->writeAs($tree, $object);

		return true;
	}

	/**
	 * The length of the longest path to a root commit: parents before children.
	 */
	private function depth(string $id): int
	{
		static $depths = [];

		if (isset($depths[$id])) {
			return $depths[$id];
		}

		$depth = 0;

		foreach ($this->repository->readCommit($id)->parents as $parent) {
			$depth = max($depth, $this->depth($parent) + 1);
		}

		return $depths[$id] = $depth;
	}

	/**
	 * A commit read from GitHub, kept in #__gitinsync_objects until it is written here:
	 * reading a long history takes several runs, and each run goes on where the last one
	 * stopped.
	 *
	 * @return  array{0: GitObject, 1: bool}  The commit, and whether it came from GitHub just now.
	 */
	private function commitFromGitHub(string $id): array
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName('data'))
			->from($this->db->quoteName(self::OBJECTS))
			->where($this->db->quoteName('id') . ' = :id')
			->bind(':id', $id);

		$data = $this->db->setQuery($query)->loadResult();
		$raw  = $data === null ? false : @gzuncompress((string) base64_decode((string) $data, true));

		if ($raw !== false) {
			return [GitObject::fromRaw($raw), false];
		}

		$object = $this->remote->fetch($id, GitObject::COMMIT);
		$row    = (object) ['id' => $id, 'type' => $object->type, 'data' => base64_encode((string) gzcompress($object->raw()))];

		try {
			$this->db->insertObject(self::OBJECTS, $row);
		} catch (\RuntimeException) {
			// Cached by a run in between.
		}

		return [$object, true];
	}

	/**
	 * @param   string[]  $ids
	 */
	private function forgetCommits(array $ids): void
	{
		foreach (array_chunk($ids, 500) as $chunk) {
			$query = $this->db->createQuery()
				->delete($this->db->quoteName(self::OBJECTS))
				->whereIn($this->db->quoteName('id'), $chunk, ParameterType::STRING);

			$this->db->setQuery($query)->execute();
		}
	}

	private function isPushed(string $id): bool
	{
		$query = $this->db->createQuery()
			->select('1')
			->from($this->db->quoteName(self::PUSHED))
			->where($this->db->quoteName('id') . ' = :id')
			->bind(':id', $id);

		return (bool) $this->db->setQuery($query)->loadResult();
	}

	private function markPushed(string $id): void
	{
		try {
			$row = (object) ['id' => $id];
			$this->db->insertObject(self::PUSHED, $row);
		} catch (\RuntimeException) {
			// Already marked.
		}
	}

	/**
	 * Once the branch is up, what GitHub has follows from its branch: the list can go.
	 */
	private function forgetPushed(): void
	{
		$this->db->setQuery($this->db->createQuery()->delete($this->db->quoteName(self::PUSHED)))->execute();
	}
}

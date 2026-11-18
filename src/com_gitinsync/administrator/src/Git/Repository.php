<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Git;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Git operations on top of an object store and a reference store.
 *
 * Files are handled as a flat map: "articles/<uuid>-<alias>/article.json" => blob id.
 * Trees are built from that map and read back into it; subtrees that didn't change keep
 * their id, so comparing two commits skips whatever they share.
 */
final class Repository
{
	/**
	 * @var array<string, Commit>
	 */
	private array $commits = [];

	/**
	 * @var array<string, Tree>
	 */
	private array $trees = [];

	public function __construct(public readonly ObjectStore $objects, public readonly RefStore $refs)
	{
	}

	public static function branchRef(string $branch): string
	{
		return 'refs/heads/' . $branch;
	}

	public function head(string $branch): ?string
	{
		return $this->refs->get(self::branchRef($branch));
	}

	/**
	 * @return  array<string, string>  Branch name => commit id.
	 */
	public function branches(): array
	{
		$branches = [];

		foreach ($this->refs->list('refs/heads/') as $name => $id) {
			$branches[substr($name, \strlen('refs/heads/'))] = $id;
		}

		return $branches;
	}

	public function readCommit(string $id): Commit
	{
		return $this->commits[$id] ??= Commit::fromObject($this->objects->read($id, GitObject::COMMIT));
	}

	public function readTree(string $id): Tree
	{
		return $this->trees[$id] ??= Tree::fromObject($this->objects->read($id, GitObject::TREE));
	}

	public function readBlob(string $id): string
	{
		return $this->objects->read($id, GitObject::BLOB)->data;
	}

	public function writeBlob(string $content): string
	{
		return $this->objects->write(GitObject::blob($content));
	}

	/**
	 * The id of the empty tree, which a first commit starts from.
	 */
	public function emptyTree(): string
	{
		return $this->objects->write((new Tree())->toObject());
	}

	/**
	 * @return  array<string, string>  Path => blob id, for every file in the tree.
	 */
	public function flatten(string $treeId, string $prefix = ''): array
	{
		$files = [];

		foreach ($this->readTree($treeId)->entries() as $name => $entry) {
			if ($entry['mode'] === Tree::MODE_TREE) {
				$files += $this->flatten($entry['id'], $prefix . $name . '/');
			} elseif ($entry['mode'] !== Tree::MODE_SUBMODULE) {
				$files[$prefix . $name] = $entry['id'];
			}
		}

		return $files;
	}

	/**
	 * The files of a commit.
	 *
	 * @return  array<string, string>
	 */
	public function files(?string $commitId): array
	{
		return $commitId === null ? [] : $this->flatten($this->readCommit($commitId)->tree);
	}

	/**
	 * Writes the trees for a flat map of files and returns the root tree's id.
	 *
	 * @param   array<string, string>  $files  Path => blob id. The blobs must be in the store.
	 */
	public function writeTree(array $files): string
	{
		$tree    = new Tree();
		$subdirs = [];

		foreach ($files as $path => $id) {
			$slash = strpos($path, '/');

			if ($slash === false) {
				$tree->add($path, Tree::MODE_FILE, $id);
			} else {
				$subdirs[substr($path, 0, $slash)][substr($path, $slash + 1)] = $id;
			}
		}

		foreach ($subdirs as $name => $children) {
			$tree->add($name, Tree::MODE_TREE, $this->writeTree($children));
		}

		$object = $tree->toObject();
		$id     = $this->objects->write($object);

		$this->trees[$id] = $tree;

		return $id;
	}

	/**
	 * @param   string[]  $parents
	 */
	public function writeCommit(
		string $tree,
		array $parents,
		Signature $author,
		Signature $committer,
		string $message
	): string {
		// Git messages end with a newline; GitHub's API keeps the message exactly as given.
		$message = rtrim(str_replace("\r\n", "\n", $message)) . "\n";
		$commit  = new Commit($tree, $parents, $author, $committer, $message);
		$id      = $this->objects->write($commit->toObject());

		$this->commits[$id] = $commit;

		return $id;
	}

	/**
	 * The paths that differ between two trees, skipping identical subtrees.
	 *
	 * @return  array<string, array{0: ?string, 1: ?string}>  Path => [blob id before, blob id after].
	 */
	public function changes(?string $fromTree, ?string $toTree, string $prefix = ''): array
	{
		if ($fromTree === $toTree) {
			return [];
		}

		$from = $fromTree === null ? [] : $this->readTree($fromTree)->entries();
		$to   = $toTree === null ? [] : $this->readTree($toTree)->entries();

		$changes = [];

		foreach (array_unique(array_merge(array_keys($from), array_keys($to))) as $name) {
			$a = $from[$name] ?? null;
			$b = $to[$name] ?? null;

			if ($a !== null && $b !== null && $a['id'] === $b['id']) {
				continue;
			}

			$aTree = $a !== null && $a['mode'] === Tree::MODE_TREE ? $a['id'] : null;
			$bTree = $b !== null && $b['mode'] === Tree::MODE_TREE ? $b['id'] : null;

			if ($aTree !== null || $bTree !== null) {
				$changes += $this->changes($aTree, $bTree, $prefix . $name . '/');
			}

			$aFile = $a !== null && $aTree === null ? $a['id'] : null;
			$bFile = $b !== null && $bTree === null ? $b['id'] : null;

			if ($aFile !== $bFile) {
				$changes[$prefix . $name] = [$aFile, $bFile];
			}
		}

		ksort($changes);

		return $changes;
	}

	/**
	 * The commits reachable from a commit, newest first.
	 *
	 * @param   (callable(Commit): ?string)|null  $key  When given, a commit is listed only when
	 *          this key differs from that of each of its parents: the history of one item.
	 *
	 * @return  array<string, Commit>  Commit id => commit.
	 */
	public function log(string $start, int $limit = 50, int $skip = 0, ?callable $key = null): array
	{
		$result  = [];
		$queue   = new \SplPriorityQueue();
		$seen    = [$start => true];
		$counter = PHP_INT_MAX;

		$queue->insert($start, [$this->readCommit($start)->committer->timestamp, $counter--]);

		while (!$queue->isEmpty() && \count($result) < $limit) {
			/** @var string $id */
			$id     = $queue->extract();
			$commit = $this->readCommit($id);

			$listed = true;

			if ($key !== null) {
				$own = $key($commit);

				if ($commit->parents === []) {
					$listed = $own !== null;
				} else {
					foreach ($commit->parents as $parent) {
						if ($key($this->readCommit($parent)) === $own) {
							$listed = false;
							break;
						}
					}
				}
			}

			if ($listed) {
				if ($skip > 0) {
					$skip--;
				} else {
					$result[$id] = $commit;
				}
			}

			foreach ($commit->parents as $parent) {
				if (!isset($seen[$parent]) && $this->objects->has($parent)) {
					$seen[$parent] = true;
					$queue->insert($parent, [$this->readCommit($parent)->committer->timestamp, $counter--]);
				}
			}
		}

		return $result;
	}

	/**
	 * The id of the tree or blob at a path inside a tree, or null.
	 */
	public function idAt(string $treeId, string $path): ?string
	{
		$id = $treeId;

		foreach (explode('/', trim($path, '/')) as $part) {
			$entries = $this->readTree($id)->entries();

			if (!isset($entries[$part])) {
				return null;
			}

			$id = $entries[$part]['id'];

			if ($entries[$part]['mode'] !== Tree::MODE_TREE) {
				break;
			}
		}

		return $id;
	}

	/**
	 * The name and id of the first entry in a directory whose name starts with a prefix.
	 *
	 * Items live in "<type>/<uuid>-<alias>/": the alias may change, the uuid doesn't.
	 *
	 * @return  array{0: string, 1: string}|null
	 */
	public function entryStartingWith(string $treeId, string $directory, string $namePrefix): ?array
	{
		$dir = $this->idAt($treeId, $directory);

		if ($dir === null) {
			return null;
		}

		foreach ($this->readTree($dir)->entries() as $name => $entry) {
			if (str_starts_with($name, $namePrefix)) {
				return [$name, $entry['id']];
			}
		}

		return null;
	}

	/**
	 * Whether $ancestor is $commit or one of its ancestors.
	 */
	public function isAncestor(string $ancestor, string $commit): bool
	{
		$stack = [$commit];
		$seen  = [];

		while ($stack !== []) {
			$id = array_pop($stack);

			if ($id === $ancestor) {
				return true;
			}

			if (isset($seen[$id]) || !$this->objects->has($id)) {
				continue;
			}

			$seen[$id] = true;

			foreach ($this->readCommit($id)->parents as $parent) {
				$stack[] = $parent;
			}
		}

		return false;
	}

	/**
	 * The best common ancestor of two commits: the newest commit both can reach.
	 */
	public function mergeBase(string $a, string $b): ?string
	{
		$ancestorsOfA = [];
		$stack        = [$a];

		while ($stack !== []) {
			$id = array_pop($stack);

			if (isset($ancestorsOfA[$id]) || !$this->objects->has($id)) {
				continue;
			}

			$ancestorsOfA[$id] = true;

			foreach ($this->readCommit($id)->parents as $parent) {
				$stack[] = $parent;
			}
		}

		// Newest first from b: the first one a can reach is the best candidate.
		$queue   = new \SplPriorityQueue();
		$seen    = [$b => true];
		$counter = PHP_INT_MAX;
		$queue->insert($b, [$this->readCommit($b)->committer->timestamp, $counter--]);

		while (!$queue->isEmpty()) {
			/** @var string $id */
			$id = $queue->extract();

			if (isset($ancestorsOfA[$id])) {
				return $id;
			}

			foreach ($this->readCommit($id)->parents as $parent) {
				if (!isset($seen[$parent]) && $this->objects->has($parent)) {
					$seen[$parent] = true;
					$queue->insert($parent, [$this->readCommit($parent)->committer->timestamp, $counter--]);
				}
			}
		}

		return null;
	}
}

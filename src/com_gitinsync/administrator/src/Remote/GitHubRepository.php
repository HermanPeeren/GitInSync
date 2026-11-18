<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Remote;

use Joomla\Database\DatabaseInterface;
use Yepr\Component\GitInSync\Administrator\Git\GitObject;
use Yepr\Component\GitInSync\Administrator\Git\ObjectStore;
use Yepr\Component\GitInSync\Administrator\Git\RefConflict;
use Yepr\Component\GitInSync\Administrator\Git\RefStore;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * GitHub as the repository ("GitHub only" mode): objects and branches through the API.
 *
 * Objects never change once written, so every object read or written is kept in
 * #__gitinsync_objects: a history or a diff doesn't ask GitHub for the same object twice.
 */
final class GitHubRepository implements ObjectStore, RefStore
{
	private const TABLE = '#__gitinsync_objects';

	/**
	 * @var array<string, GitObject>
	 */
	private array $memory = [];

	public function __construct(private readonly GitHubObjects $objects, private readonly DatabaseInterface $db)
	{
	}

	/**
	 * Every id is taken to be on GitHub: asking would cost a request per object, and
	 * reading one that isn't there fails clearly anyway.
	 */
	public function has(string $id): bool
	{
		return GitObject::isId($id);
	}

	public function read(string $id, ?string $type = null): GitObject
	{
		$object = $this->cached($id);

		if ($object === null) {
			if ($type === null) {
				throw new \InvalidArgumentException('Reading from GitHub needs the object type.');
			}

			$object = $this->objects->fetch($id, $type);
			$this->cache($id, $object);
		}

		return $object;
	}

	public function write(GitObject $object): string
	{
		$id = $object->id();

		if ($this->cached($id) === null) {
			$this->objects->create($object);
			$this->cache($id, $object);
		}

		return $id;
	}

	public function get(string $name): ?string
	{
		return $this->objects->client()->getRef($this->branch($name));
	}

	public function set(string $name, string $id, string|null|false $expected = false): void
	{
		$branch  = $this->branch($name);
		$client  = $this->objects->client();
		$current = $client->getRef($branch);

		if ($expected !== false && $current !== $expected) {
			throw new RefConflict(\sprintf('%s has moved on GitHub since it was read.', $branch));
		}

		try {
			if ($current === null) {
				$client->createRef($branch, $id);
			} else {
				// Not forced: GitHub itself refuses anything but a step forward.
				$client->updateRef($branch, $id, $expected === false);
			}
		} catch (GitHubError $e) {
			if ($e->status === 422) {
				throw new RefConflict(\sprintf('GitHub refused to move %s: %s', $branch, $e->getMessage()));
			}

			throw $e;
		}
	}

	public function delete(string $name): void
	{
		$this->objects->client()->deleteRef($this->branch($name));
	}

	public function list(string $prefix = 'refs/heads/'): array
	{
		if ($prefix !== 'refs/heads/') {
			return [];
		}

		$refs = [];

		foreach ($this->objects->client()->branches() as $branch => $id) {
			$refs['refs/heads/' . $branch] = $id;
		}

		ksort($refs);

		return $refs;
	}

	private function branch(string $name): string
	{
		if (!str_starts_with($name, 'refs/heads/')) {
			throw new \InvalidArgumentException(\sprintf('Only branches are on GitHub here, not %s.', $name));
		}

		return substr($name, \strlen('refs/heads/'));
	}

	private function cached(string $id): ?GitObject
	{
		if (isset($this->memory[$id])) {
			return $this->memory[$id];
		}

		$query = $this->db->createQuery()
			->select($this->db->quoteName('data'))
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('id') . ' = :id')
			->bind(':id', $id);

		$data = $this->db->setQuery($query)->loadResult();

		if ($data === null) {
			return null;
		}

		$raw = @gzuncompress((string) base64_decode((string) $data, true));

		return $raw === false ? null : $this->memory[$id] = GitObject::fromRaw($raw);
	}

	private function cache(string $id, GitObject $object): void
	{
		$this->memory[$id] = $object;

		try {
			$row = (object) [
				'id'   => $id,
				'type' => $object->type,
				'data' => base64_encode((string) gzcompress($object->raw())),
			];
			$this->db->insertObject(self::TABLE, $row);
		} catch (\RuntimeException) {
			// Already cached by a run in between.
		}
	}
}

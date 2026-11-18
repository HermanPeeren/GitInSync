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
 * Objects and references in memory: for the tests, and for computing a merge result
 * before anything is written.
 */
final class MemoryRepository implements ObjectStore, RefStore
{
	/**
	 * @var array<string, GitObject>
	 */
	private array $objects = [];

	/**
	 * @var array<string, string>
	 */
	private array $refs = [];

	public function has(string $id): bool
	{
		return isset($this->objects[$id]);
	}

	public function read(string $id, ?string $type = null): GitObject
	{
		$object = $this->objects[$id] ?? throw new ObjectNotFound(\sprintf('Object %s is not in memory.', $id));

		if ($type !== null && $object->type !== $type) {
			throw new CorruptObject(\sprintf('Object %s is a %s, not a %s.', $id, $object->type, $type));
		}

		return $object;
	}

	public function write(GitObject $object): string
	{
		$id                 = $object->id();
		$this->objects[$id] = $object;

		return $id;
	}

	public function get(string $name): ?string
	{
		return $this->refs[$name] ?? null;
	}

	public function set(string $name, string $id, string|null|false $expected = false): void
	{
		if ($expected !== false && ($this->refs[$name] ?? null) !== $expected) {
			throw new RefConflict(\sprintf('%s has moved since it was read.', $name));
		}

		$this->refs[$name] = $id;
	}

	public function delete(string $name): void
	{
		unset($this->refs[$name]);
	}

	public function list(string $prefix = 'refs/heads/'): array
	{
		$refs = array_filter($this->refs, static fn (string $name): bool => str_starts_with($name, $prefix), ARRAY_FILTER_USE_KEY);
		ksort($refs);

		return $refs;
	}

	public function count(): int
	{
		return \count($this->objects);
	}
}

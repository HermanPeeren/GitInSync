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
 * A tree object: one directory level, with files (blobs) and subdirectories (trees).
 */
final class Tree
{
	public const MODE_FILE       = '100644';
	public const MODE_EXECUTABLE = '100755';
	public const MODE_TREE       = '40000';
	public const MODE_SYMLINK    = '120000';
	public const MODE_SUBMODULE  = '160000';

	/**
	 * @var array<string, array{mode: string, id: string}>  Name => mode and id.
	 */
	private array $entries = [];

	/**
	 * @param   array<string, array{mode: string, id: string}>  $entries
	 */
	public function __construct(array $entries = [])
	{
		foreach ($entries as $name => $entry) {
			$this->add($name, $entry['mode'], $entry['id']);
		}
	}

	public function add(string $name, string $mode, string $id): void
	{
		if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, "\0")) {
			throw new \InvalidArgumentException(\sprintf('Bad name in tree: "%s".', $name));
		}

		// GitHub's API writes "040000"; inside the object it is "40000".
		$mode = ltrim($mode, '0');

		$this->entries[$name] = ['mode' => $mode, 'id' => $id];
	}

	/**
	 * @return  array<string, array{mode: string, id: string}>  In git's order.
	 */
	public function entries(): array
	{
		$entries = $this->entries;

		// Git sorts by name, comparing a directory as if its name ended in "/".
		uksort($entries, fn (string $a, string $b): int => strcmp(
			$entries[$a]['mode'] === self::MODE_TREE ? $a . '/' : $a,
			$entries[$b]['mode'] === self::MODE_TREE ? $b . '/' : $b
		));

		return $entries;
	}

	public function toObject(): GitObject
	{
		$data = '';

		foreach ($this->entries() as $name => $entry) {
			$data .= $entry['mode'] . ' ' . $name . "\0" . hex2bin($entry['id']);
		}

		return new GitObject(GitObject::TREE, $data);
	}

	public static function fromObject(GitObject $object): self
	{
		if ($object->type !== GitObject::TREE) {
			throw new CorruptObject(\sprintf('Expected a tree, got a %s.', $object->type));
		}

		$tree   = new self();
		$data   = $object->data;
		$offset = 0;
		$length = \strlen($data);

		while ($offset < $length) {
			$space = strpos($data, ' ', $offset);
			$nul   = $space === false ? false : strpos($data, "\0", $space);

			if ($space === false || $nul === false || $nul + 21 > $length) {
				throw new CorruptObject('Truncated tree object.');
			}

			$mode = substr($data, $offset, $space - $offset);
			$name = substr($data, $space + 1, $nul - $space - 1);
			$id   = bin2hex(substr($data, $nul + 1, 20));

			$tree->entries[$name] = ['mode' => $mode, 'id' => $id];
			$offset               = $nul + 21;
		}

		return $tree;
	}
}

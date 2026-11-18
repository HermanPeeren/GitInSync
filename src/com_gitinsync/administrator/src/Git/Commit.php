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
 * A commit object.
 *
 * Headers GitInSync doesn't write itself (a signature, "encoding", ...) are kept as they
 * are, so a commit read from elsewhere is written back with the same id.
 */
final class Commit
{
	/**
	 * @param   string[]                       $parents
	 * @param   array<int, array{0: string, 1: string}>  $extraHeaders  Name and value, in order, after "committer".
	 */
	public function __construct(
		public readonly string $tree,
		public readonly array $parents,
		public readonly Signature $author,
		public readonly Signature $committer,
		public readonly string $message,
		public readonly array $extraHeaders = []
	) {
	}

	/**
	 * The first line of the message.
	 */
	public function subject(): string
	{
		return trim(strtok($this->message, "\n") ?: '');
	}

	public function toObject(): GitObject
	{
		$data = 'tree ' . $this->tree . "\n";

		foreach ($this->parents as $parent) {
			$data .= 'parent ' . $parent . "\n";
		}

		$data .= 'author ' . $this->author->format() . "\n";
		$data .= 'committer ' . $this->committer->format() . "\n";

		foreach ($this->extraHeaders as [$name, $value]) {
			// A multi-line value continues on lines that start with a space.
			$data .= $name . ' ' . str_replace("\n", "\n ", $value) . "\n";
		}

		return new GitObject(GitObject::COMMIT, $data . "\n" . $this->message);
	}

	public static function fromObject(GitObject $object): self
	{
		if ($object->type !== GitObject::COMMIT) {
			throw new CorruptObject(\sprintf('Expected a commit, got a %s.', $object->type));
		}

		$end = strpos($object->data, "\n\n");

		if ($end === false) {
			$headerText = rtrim($object->data, "\n");
			$message    = '';
		} else {
			$headerText = substr($object->data, 0, $end);
			$message    = substr($object->data, $end + 2);
		}

		$headers = [];

		foreach (explode("\n", $headerText) as $line) {
			if (str_starts_with($line, ' ') && $headers !== []) {
				$headers[\count($headers) - 1][1] .= "\n" . substr($line, 1);

				continue;
			}

			$parts     = explode(' ', $line, 2);
			$headers[] = [$parts[0], $parts[1] ?? ''];
		}

		$tree      = null;
		$parents   = [];
		$author    = null;
		$committer = null;
		$extra     = [];

		foreach ($headers as [$name, $value]) {
			match (true) {
				$name === 'tree' && $tree === null           => $tree = $value,
				$name === 'parent'                           => $parents[] = $value,
				$name === 'author' && $author === null       => $author = Signature::parse($value),
				$name === 'committer' && $committer === null => $committer = Signature::parse($value),
				default                                      => $extra[] = [$name, $value],
			};
		}

		if ($tree === null || $author === null || $committer === null) {
			throw new CorruptObject('Commit without tree, author or committer.');
		}

		// Ids are used in paths, URLs and pages: only real ones get through.
		foreach ([$tree, ...$parents] as $id) {
			if (!GitObject::isId($id)) {
				throw new CorruptObject('Commit with an invalid object id.');
			}
		}

		return new self($tree, $parents, $author, $committer, $message, $extra);
	}
}

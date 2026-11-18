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
 * A git object as git stores it: a type and the raw bytes.
 *
 * The id is the SHA-1 of "<type> <size>\0<data>", the same as `git hash-object` gives.
 * Because the id depends on nothing but the content, an object written here and the same
 * object created through GitHub's API have the same id.
 */
final class GitObject
{
	public const BLOB   = 'blob';
	public const TREE   = 'tree';
	public const COMMIT = 'commit';

	private ?string $id = null;

	public function __construct(public readonly string $type, public readonly string $data)
	{
		if (!\in_array($type, [self::BLOB, self::TREE, self::COMMIT], true)) {
			throw new \InvalidArgumentException(\sprintf('Unsupported git object type "%s".', $type));
		}
	}

	public static function blob(string $data): self
	{
		return new self(self::BLOB, $data);
	}

	/**
	 * The header and data, as hashed and (compressed) stored.
	 */
	public function raw(): string
	{
		return $this->type . ' ' . \strlen($this->data) . "\0" . $this->data;
	}

	/**
	 * The 40-character hexadecimal id.
	 */
	public function id(): string
	{
		return $this->id ??= sha1($this->raw());
	}

	/**
	 * Parses "<type> <size>\0<data>".
	 */
	public static function fromRaw(string $raw): self
	{
		$nul = strpos($raw, "\0");

		if ($nul === false) {
			throw new CorruptObject('No header in git object.');
		}

		$header = substr($raw, 0, $nul);
		$parts  = explode(' ', $header, 2);

		if (\count($parts) !== 2 || !ctype_digit($parts[1])) {
			throw new CorruptObject(\sprintf('Bad git object header "%s".', $header));
		}

		$data = substr($raw, $nul + 1);

		if (\strlen($data) !== (int) $parts[1]) {
			throw new CorruptObject('Git object size does not match its header.');
		}

		return new self($parts[0], $data);
	}

	public static function isId(string $value): bool
	{
		return \strlen($value) === 40 && ctype_xdigit($value) && strtolower($value) === $value;
	}
}

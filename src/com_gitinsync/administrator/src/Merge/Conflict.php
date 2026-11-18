<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Merge;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One item that couldn't be merged by itself.
 */
final class Conflict
{
	/**
	 * Changed on both sides, differently.
	 */
	public const CONTENT = 'content';

	/**
	 * Deleted on one side, changed on the other.
	 */
	public const DELETED = 'deleted';

	/**
	 * @param   string                                                        $key      The item ("articles/<uuid>") or a plain path.
	 * @param   array{folder: string, files: array<string, string>}|null      $base
	 * @param   array{folder: string, files: array<string, string>}|null      $ours
	 * @param   array{folder: string, files: array<string, string>}|null      $theirs
	 * @param   array<int, array<string, mixed>>|null                         $details  For CONTENT: per file and field what differs.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $kind,
		public readonly ?array $base,
		public readonly ?array $ours,
		public readonly ?array $theirs,
		public readonly ?array $details
	) {
	}

	/**
	 * @return  array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'key'     => $this->key,
			'kind'    => $this->kind,
			'base'    => $this->base,
			'ours'    => $this->ours,
			'theirs'  => $this->theirs,
			'details' => $this->details,
		];
	}

	/**
	 * @param   array<string, mixed>  $data
	 */
	public static function fromArray(array $data): self
	{
		return new self((string) $data['key'], (string) $data['kind'], $data['base'], $data['ours'], $data['theirs'], $data['details']);
	}
}

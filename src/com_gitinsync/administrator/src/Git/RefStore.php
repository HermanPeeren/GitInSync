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
 * Branches: names like "refs/heads/main" pointing at a commit id.
 */
interface RefStore
{
	public function get(string $name): ?string;

	/**
	 * Points the reference at a commit.
	 *
	 * @param   string|null  $expected  The id the caller read before. When the reference
	 *                                  points elsewhere now, nothing changes and RefConflict is
	 *                                  thrown. Null means the reference must not exist yet.
	 *                                  Pass false to skip the check.
	 *
	 * @throws  RefConflict
	 */
	public function set(string $name, string $id, string|null|false $expected = false): void;

	public function delete(string $name): void;

	/**
	 * @return  array<string, string>  Reference name => commit id, for names under the prefix.
	 */
	public function list(string $prefix = 'refs/heads/'): array;
}

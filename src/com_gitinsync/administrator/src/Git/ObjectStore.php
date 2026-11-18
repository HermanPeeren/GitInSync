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
 * Where git objects live: a local repository on disk, or GitHub through its API.
 *
 * Everything above this (commits, diffs, merges, history) is written once against this
 * interface.
 */
interface ObjectStore
{
	public function has(string $id): bool;

	/**
	 * @param   string|null  $type  The type the caller expects. A store that can only fetch by
	 *                              type (GitHub's API has an endpoint per type) needs it.
	 *
	 * @throws  ObjectNotFound
	 */
	public function read(string $id, ?string $type = null): GitObject;

	/**
	 * Stores the object, if it isn't there yet, and returns its id.
	 */
	public function write(GitObject $object): string;
}

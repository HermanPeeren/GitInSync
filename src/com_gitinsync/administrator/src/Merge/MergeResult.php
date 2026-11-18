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
 * The files after a merge, and what still needs a decision.
 */
final class MergeResult
{
	/**
	 * @param   array<string, string>  $files      Path => blob id.
	 * @param   Conflict[]             $conflicts
	 */
	public function __construct(public readonly array $files, public readonly array $conflicts)
	{
	}

	public function isClean(): bool
	{
		return $this->conflicts === [];
	}
}

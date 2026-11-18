<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Remote;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * GitHub answered with an error. The status says what kind: 401 a bad token, 403 no
 * permission or the rate limit, 404 no such repository (or no access to it), 409 an empty
 * repository, 422 a branch that can't be moved like that.
 */
final class GitHubError extends \RuntimeException
{
	public function __construct(string $message, public readonly int $status)
	{
		parent::__construct($message, $status);
	}
}

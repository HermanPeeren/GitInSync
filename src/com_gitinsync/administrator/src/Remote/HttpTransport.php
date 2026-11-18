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
 * One HTTP request. Behind an interface so the GitHub client can be tested without GitHub.
 */
interface HttpTransport
{
	/**
	 * @param   array<string, string>  $headers
	 *
	 * @return  array{status: int, headers: array<string, string>, body: string}  Header names in lower case.
	 */
	public function request(string $method, string $url, array $headers, ?string $body): array;
}

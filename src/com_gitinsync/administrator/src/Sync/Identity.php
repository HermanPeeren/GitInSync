<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Sync;

use Joomla\CMS\User\UserFactoryInterface;
use Yepr\Component\GitInSync\Administrator\Git\Signature;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Who a commit says made it.
 *
 * The author is the editor; by default with a no-reply address, because a real email
 * address in a public repository is there for good. The committer is always GitInSync on
 * this site, so it is clear which site made a commit.
 */
final class Identity
{
	public function __construct(private readonly Settings $settings, private readonly UserFactoryInterface $users)
	{
	}

	public function author(int $userId, ?int $timestamp = null): Signature
	{
		$timestamp ??= time();

		if ($this->settings->identity() === Settings::IDENTITY_FIXED || $userId <= 0) {
			return new Signature($this->settings->fixedName(), $this->settings->fixedEmail(), $timestamp);
		}

		$user = $this->users->loadUserById($userId);

		if ((int) $user->id !== $userId) {
			return new Signature($this->settings->fixedName(), $this->settings->fixedEmail(), $timestamp);
		}

		$email = $this->settings->identity() === Settings::IDENTITY_REAL && $user->email !== ''
			? $user->email
			: \sprintf(
				'%d+%s@users.noreply.%s',
				$userId,
				preg_replace('/[^A-Za-z0-9._-]/', '', (string) $user->username),
				$this->settings->siteHost()
			);

		return new Signature((string) $user->name, $email, $timestamp);
	}

	public function committer(): Signature
	{
		return new Signature('GitInSync', 'gitinsync@' . $this->settings->siteHost(), time());
	}
}

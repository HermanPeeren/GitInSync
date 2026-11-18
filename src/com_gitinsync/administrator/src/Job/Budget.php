<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Job;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * How long one run may work. Everything long (export, push, fetch, applying a branch)
 * does a portion, checks this, and goes on in the next run.
 */
final class Budget
{
	private readonly float $deadline;

	public function __construct(float $seconds)
	{
		$this->deadline = microtime(true) + $seconds;
	}

	public static function unlimited(): self
	{
		return new self(PHP_INT_MAX);
	}

	public function expired(): bool
	{
		return microtime(true) >= $this->deadline;
	}

	public function left(): float
	{
		return max(0.0, $this->deadline - microtime(true));
	}
}

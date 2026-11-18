<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Sync;

use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The component's options, read once and typed.
 */
final class Settings
{
	public const MODE_LOCAL        = 'local';
	public const MODE_LOCAL_GITHUB = 'local_github';
	public const MODE_GITHUB       = 'github';

	public const NON_PUBLIC_PRIVATE_ONLY = 'private_only';
	public const NON_PUBLIC_ALWAYS       = 'always';
	public const NON_PUBLIC_NEVER        = 'never';

	public const IDENTITY_NOREPLY = 'noreply';
	public const IDENTITY_REAL    = 'real';
	public const IDENTITY_FIXED   = 'fixed';

	public const ALL_TYPES = ['category', 'fieldgroup', 'field', 'tag', 'article', 'menu', 'menuitem'];

	public function __construct(private readonly Registry $params, private readonly string $siteRoot, private readonly string $siteHost)
	{
	}

	public function mode(): string
	{
		$mode = (string) $this->params->get('mode', self::MODE_LOCAL);

		return \in_array($mode, [self::MODE_LOCAL, self::MODE_LOCAL_GITHUB, self::MODE_GITHUB], true) ? $mode : self::MODE_LOCAL;
	}

	public function usesLocal(): bool
	{
		return $this->mode() !== self::MODE_GITHUB;
	}

	public function usesGitHub(): bool
	{
		return $this->mode() !== self::MODE_LOCAL;
	}

	/**
	 * The local repository. A relative path is relative to the site's root; the default is
	 * a folder next to the site, outside the web root.
	 */
	public function repositoryPath(): string
	{
		$path = trim((string) $this->params->get('repository_path', ''));

		if ($path === '') {
			return \dirname($this->siteRoot) . '/gitinsync-repository';
		}

		if (!preg_match('#^([a-zA-Z]:[\\\\/]|/|\\\\\\\\)#', $path)) {
			$path = $this->siteRoot . '/' . $path;
		}

		return self::normalise(str_replace('\\', '/', $path));
	}

	/**
	 * Resolves "." and ".." without touching the disk: the folder may not exist yet.
	 */
	private static function normalise(string $path): string
	{
		$root  = preg_match('#^([a-zA-Z]:/|//|/)#', $path, $m) ? $m[1] : '';
		$parts = [];

		foreach (explode('/', substr($path, \strlen($root))) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}

			if ($part === '..' && $parts !== [] && end($parts) !== '..') {
				array_pop($parts);

				continue;
			}

			$parts[] = $part;
		}

		return $root . implode('/', $parts);
	}

	public function githubOwner(): string
	{
		return trim((string) $this->params->get('github_owner', ''));
	}

	public function githubRepository(): string
	{
		return trim((string) $this->params->get('github_repository', ''));
	}

	public function githubToken(): string
	{
		return trim((string) $this->params->get('github_token', ''));
	}

	public function hasGitHubCredentials(): bool
	{
		return $this->githubOwner() !== '' && $this->githubRepository() !== '' && $this->githubToken() !== '';
	}

	/**
	 * The branch a new site starts on.
	 */
	public function defaultBranch(): string
	{
		$branch = trim((string) $this->params->get('default_branch', 'main'));

		return $branch === '' ? 'main' : $branch;
	}

	/**
	 * @return  string[]
	 */
	public function contentTypes(): array
	{
		$types = $this->params->get('content_types', self::ALL_TYPES);
		$types = \is_array($types) ? $types : explode(',', (string) $types);

		return array_values(array_intersect(self::ALL_TYPES, array_map('strval', $types)));
	}

	public function nonPublicPolicy(): string
	{
		$policy = (string) $this->params->get('non_public', self::NON_PUBLIC_PRIVATE_ONLY);

		return \in_array($policy, [self::NON_PUBLIC_PRIVATE_ONLY, self::NON_PUBLIC_ALWAYS, self::NON_PUBLIC_NEVER], true)
			? $policy
			: self::NON_PUBLIC_PRIVATE_ONLY;
	}

	public function includeTrashed(): bool
	{
		return (bool) $this->params->get('include_trashed', 0);
	}

	public function commitOnSave(): bool
	{
		return (bool) $this->params->get('commit_on_save', 0);
	}

	public function identity(): string
	{
		$identity = (string) $this->params->get('author_identity', self::IDENTITY_NOREPLY);

		return \in_array($identity, [self::IDENTITY_NOREPLY, self::IDENTITY_REAL, self::IDENTITY_FIXED], true)
			? $identity
			: self::IDENTITY_NOREPLY;
	}

	public function fixedName(): string
	{
		return trim((string) $this->params->get('fixed_name', '')) ?: 'GitInSync';
	}

	public function fixedEmail(): string
	{
		return trim((string) $this->params->get('fixed_email', '')) ?: 'gitinsync@' . $this->siteHost;
	}

	public function siteHost(): string
	{
		return $this->siteHost;
	}

	/**
	 * Seconds a run from the browser may spend: PHP's and the server's limits are usually
	 * 30 seconds, and an HTTP 500 without a log entry is what happens beyond them.
	 */
	public function timeBudget(): int
	{
		return max(3, min(120, (int) $this->params->get('time_budget', 20)));
	}
}

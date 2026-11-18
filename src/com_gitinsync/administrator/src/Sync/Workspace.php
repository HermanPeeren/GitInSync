<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Sync;

use Joomla\Database\DatabaseInterface;
use Yepr\Component\GitInSync\Administrator\Git\GitException;
use Yepr\Component\GitInSync\Administrator\Git\LocalRepository;
use Yepr\Component\GitInSync\Administrator\Git\Repository;
use Yepr\Component\GitInSync\Administrator\Remote\GitHubClient;
use Yepr\Component\GitInSync\Administrator\Remote\GitHubObjects;
use Yepr\Component\GitInSync\Administrator\Remote\GitHubRepository;
use Yepr\Component\GitInSync\Administrator\Remote\HttpTransport;
use Yepr\Component\GitInSync\Administrator\Remote\Transfer;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The repository this site works with, as the options say, and the branch it follows.
 */
final class Workspace
{
	private ?Repository $repository = null;

	private ?LocalRepository $local = null;

	private ?GitHubObjects $github = null;

	public function __construct(
		private readonly Settings $settings,
		private readonly StateStore $state,
		private readonly DatabaseInterface $db,
		private readonly HttpTransport $http
	) {
	}

	public function settings(): Settings
	{
		return $this->settings;
	}

	public function repository(): Repository
	{
		if ($this->repository !== null) {
			return $this->repository;
		}

		if ($this->settings->usesLocal()) {
			$local = $this->local();
			$local->init($this->branch());

			return $this->repository = new Repository($local, $local);
		}

		$github = new GitHubRepository($this->github(), $this->db);

		return $this->repository = new Repository($github, $github);
	}

	public function local(): LocalRepository
	{
		return $this->local ??= new LocalRepository($this->settings->repositoryPath());
	}

	public function github(): GitHubObjects
	{
		if (!$this->settings->hasGitHubCredentials()) {
			throw new GitException('Fill in the GitHub owner, repository and token in the options first.');
		}

		return $this->github ??= new GitHubObjects(new GitHubClient(
			$this->http,
			$this->settings->githubOwner(),
			$this->settings->githubRepository(),
			$this->settings->githubToken()
		));
	}

	/**
	 * Push and fetch, in "local + GitHub" mode; null otherwise.
	 */
	public function transfer(): ?Transfer
	{
		if ($this->settings->mode() !== Settings::MODE_LOCAL_GITHUB) {
			return null;
		}

		$this->repository();

		return new Transfer($this->local(), $this->repository(), $this->github(), $this->db);
	}

	/**
	 * The branch this site commits to and follows.
	 */
	public function branch(): string
	{
		return $this->state->get('branch') ?? $this->settings->defaultBranch();
	}

	public function setBranch(string $branch): void
	{
		$this->state->set('branch', $branch);

		if ($this->settings->usesLocal() && $this->local()->exists()) {
			$this->local()->setHeadBranch($branch);
		}
	}

	/**
	 * The commit the site's content is: the parent of the next commit.
	 *
	 * Locally that is the branch. On GitHub the branch can move without the site knowing
	 * (a pull request merged there), so the site remembers its own commit; until it pulls,
	 * committing on top of the moved branch is refused instead of undoing what came in.
	 */
	public function head(): ?string
	{
		if ($this->settings->usesLocal()) {
			return $this->repository()->head($this->branch());
		}

		return $this->state->get('site.commit.' . $this->branch()) ?? $this->repository()->head($this->branch());
	}

	/**
	 * The branch as it is in the repository (on GitHub, in GitHub-only mode).
	 */
	public function branchHead(?string $branch = null): ?string
	{
		return $this->repository()->head($branch ?? $this->branch());
	}

	/**
	 * Moves the site's branch to a commit, if it is still where the site left it.
	 *
	 * @throws  \Yepr\Component\GitInSync\Administrator\Git\RefConflict
	 */
	public function moveHead(string $commit, ?string $expected): void
	{
		$branch = $this->branch();
		$ref    = Repository::branchRef($branch);

		// Already there: a fast-forward to what GitHub has.
		if ($this->repository()->refs->get($ref) !== $commit) {
			$this->repository()->refs->set($ref, $commit, $expected);
		}

		if (!$this->settings->usesLocal()) {
			$this->state->set('site.commit.' . $branch, $commit);
		}
	}

	/**
	 * Whether non-public content may be stored, as the options and the repository's
	 * visibility say. The visibility is asked once an hour.
	 */
	public function mayStoreNonPublic(): bool
	{
		return match ($this->settings->nonPublicPolicy()) {
			Settings::NON_PUBLIC_ALWAYS => true,
			Settings::NON_PUBLIC_NEVER  => false,
			default                     => !$this->settings->usesGitHub() || $this->isGitHubPrivate(),
		};
	}

	public function isGitHubPrivate(bool $refresh = false): bool
	{
		$cached = $this->state->get('github.private');
		$time   = (int) $this->state->get('github.private.time', '0');

		if (!$refresh && $cached !== null && $time > time() - 3600) {
			return $cached === '1';
		}

		// Unknown counts as public: the safe side.
		try {
			$private = $this->github()->client()->repository()['private'];
		} catch (\RuntimeException) {
			return $cached === '1';
		}

		$this->state->set('github.private', $private ? '1' : '0');
		$this->state->set('github.private.time', (string) time());

		return $private;
	}
}

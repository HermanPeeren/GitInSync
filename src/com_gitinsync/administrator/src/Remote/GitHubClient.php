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
 * The parts of GitHub's REST API that GitInSync uses: the repository, and its Git Data
 * endpoints (blobs, trees, commits, references).
 *
 * The token is a fine-grained personal access token for one repository, with "Contents:
 * read and write". It is sent only to api.github.com.
 */
final class GitHubClient
{
	private const API = 'https://api.github.com';

	/**
	 * Requests left in this hour, from the last answer; null before the first request.
	 */
	private ?int $remaining = null;

	private int $requests = 0;

	public function __construct(
		private readonly HttpTransport $http,
		private readonly string $owner,
		private readonly string $repository,
		private readonly string $token
	) {
		if (!preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || !preg_match('/^[A-Za-z0-9_.-]+$/', $repository)) {
			throw new \InvalidArgumentException('The GitHub owner or repository name has characters GitHub doesn\'t allow.');
		}
	}

	public function remaining(): ?int
	{
		return $this->remaining;
	}

	public function requests(): int
	{
		return $this->requests;
	}

	/**
	 * @return  array{private: bool, default_branch: string, html_url: string, empty: bool}
	 */
	public function repository(): array
	{
		$repo = $this->call('GET', '');

		return [
			'private'        => (bool) ($repo['private'] ?? true),
			'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
			'html_url'       => (string) ($repo['html_url'] ?? ''),
			'empty'          => (int) ($repo['size'] ?? 0) === 0 && $this->getRef((string) ($repo['default_branch'] ?? 'main')) === null,
		];
	}

	/**
	 * The Git Data API refuses to work on an empty repository. A first file through the
	 * Contents API gives it a first commit.
	 */
	public function initialise(string $branch): string
	{
		$result = $this->call('PUT', '/contents/README.md', [
			'message' => 'Start the repository for GitInSync',
			'content' => base64_encode("# Content repository\n\nThis repository holds the content of a Joomla site, written by GitInSync.\n"),
			'branch'  => $branch,
		]);

		return (string) ($result['commit']['sha'] ?? '');
	}

	public function getRef(string $branch): ?string
	{
		try {
			$ref = $this->call('GET', '/git/ref/heads/' . $this->branchPath($branch));
		} catch (GitHubError $e) {
			if ($e->status === 404 || $e->status === 409) {
				return null;
			}

			throw $e;
		}

		return isset($ref['object']['sha']) ? (string) $ref['object']['sha'] : null;
	}

	/**
	 * @return  array<string, string>  Branch => commit id.
	 */
	public function branches(): array
	{
		try {
			$refs = $this->call('GET', '/git/matching-refs/heads/');
		} catch (GitHubError $e) {
			if ($e->status === 409) {
				return [];
			}

			throw $e;
		}

		$branches = [];

		foreach ($refs as $ref) {
			if (\is_array($ref) && isset($ref['ref'], $ref['object']['sha'])) {
				$branches[substr((string) $ref['ref'], \strlen('refs/heads/'))] = (string) $ref['object']['sha'];
			}
		}

		return $branches;
	}

	public function createRef(string $branch, string $sha): void
	{
		$this->call('POST', '/git/refs', ['ref' => 'refs/heads/' . $branch, 'sha' => $sha]);
	}

	/**
	 * Without force, GitHub only moves a branch forward (to a descendant): 422 otherwise.
	 */
	public function updateRef(string $branch, string $sha, bool $force = false): void
	{
		$this->call('PATCH', '/git/refs/heads/' . $this->branchPath($branch), ['sha' => $sha, 'force' => $force]);
	}

	public function deleteRef(string $branch): void
	{
		$this->call('DELETE', '/git/refs/heads/' . $this->branchPath($branch));
	}

	public function createBlob(string $content): string
	{
		return (string) $this->call('POST', '/git/blobs', ['content' => base64_encode($content), 'encoding' => 'base64'])['sha'];
	}

	public function getBlob(string $sha): string
	{
		$blob    = $this->call('GET', '/git/blobs/' . $sha);
		$content = base64_decode(str_replace("\n", '', (string) ($blob['content'] ?? '')), true);

		if ($content === false) {
			throw new GitHubError(\sprintf('Blob %s came back unreadable.', $sha), 500);
		}

		return $content;
	}

	/**
	 * @param   array<int, array{path: string, mode: string, type: string, sha: string}>  $entries
	 */
	public function createTree(array $entries): string
	{
		return (string) $this->call('POST', '/git/trees', ['tree' => $entries])['sha'];
	}

	/**
	 * @return  array<int, array{path: string, mode: string, type: string, sha: string}>
	 */
	public function getTree(string $sha): array
	{
		$tree    = $this->call('GET', '/git/trees/' . $sha);
		$entries = [];

		foreach ((array) ($tree['tree'] ?? []) as $entry) {
			if (\is_array($entry)) {
				$entries[] = [
					'path' => (string) $entry['path'],
					'mode' => (string) $entry['mode'],
					'type' => (string) $entry['type'],
					'sha'  => (string) $entry['sha'],
				];
			}
		}

		return $entries;
	}

	/**
	 * @param   string[]                                          $parents
	 * @param   array{name: string, email: string, date: string}  $author
	 * @param   array{name: string, email: string, date: string}  $committer
	 */
	public function createCommit(string $message, string $tree, array $parents, array $author, array $committer): string
	{
		return (string) $this->call('POST', '/git/commits', [
			'message'   => $message,
			'tree'      => $tree,
			'parents'   => $parents,
			'author'    => $author,
			'committer' => $committer,
		])['sha'];
	}

	/**
	 * @return  array<string, mixed>
	 */
	public function getCommit(string $sha): array
	{
		return $this->call('GET', '/git/commits/' . $sha);
	}

	public function commitExists(string $sha): bool
	{
		try {
			$this->getCommit($sha);

			return true;
		} catch (GitHubError $e) {
			if ($e->status === 404 || $e->status === 422) {
				return false;
			}

			throw $e;
		}
	}

	private function branchPath(string $branch): string
	{
		return implode('/', array_map('rawurlencode', explode('/', $branch)));
	}

	/**
	 * @param   array<string, mixed>|null  $body
	 *
	 * @return  array<mixed>
	 */
	private function call(string $method, string $path, ?array $body = null): array
	{
		$url = self::API . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repository) . $path;

		$response = $this->http->request($method, $url, [
			'Accept'               => 'application/vnd.github+json',
			'Authorization'        => 'Bearer ' . $this->token,
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'GitInSync',
			'Content-Type'         => 'application/json',
		], $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

		$this->requests++;

		if (isset($response['headers']['x-ratelimit-remaining'])) {
			$this->remaining = (int) $response['headers']['x-ratelimit-remaining'];
		}

		$data = $response['body'] === '' ? [] : json_decode($response['body'], true);

		if ($response['status'] >= 400) {
			$message = \is_array($data) && isset($data['message']) ? (string) $data['message'] : 'HTTP ' . $response['status'];

			if ($response['status'] === 403 && $this->remaining === 0) {
				$message = 'GitHub\'s rate limit is reached; the next run goes on.';
			}

			throw new GitHubError(\sprintf('GitHub: %s (%s %s)', $message, $method, $path === '' ? '/' : $path), $response['status']);
		}

		return \is_array($data) ? $data : [];
	}
}

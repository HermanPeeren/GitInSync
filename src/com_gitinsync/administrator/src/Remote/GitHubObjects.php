<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Remote;

use Yepr\Component\GitInSync\Administrator\Git\Commit;
use Yepr\Component\GitInSync\Administrator\Git\GitException;
use Yepr\Component\GitInSync\Administrator\Git\GitObject;
use Yepr\Component\GitInSync\Administrator\Git\Signature;
use Yepr\Component\GitInSync\Administrator\Git\Tree;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Git objects to and from GitHub's Git Data API.
 *
 * A git object's id depends only on its content, so an object created through the API
 * must come back with the id it has here. That is checked every time: a different id
 * means the content differs, and continuing would make two histories.
 */
final class GitHubObjects
{
	public function __construct(private readonly GitHubClient $client)
	{
	}

	public function client(): GitHubClient
	{
		return $this->client;
	}

	/**
	 * Creates the object on GitHub. Objects it refers to must be there already.
	 */
	public function create(GitObject $object): string
	{
		$expected = $object->id();

		$id = match ($object->type) {
			GitObject::BLOB   => $this->client->createBlob($object->data),
			GitObject::TREE   => $this->client->createTree($this->treeEntries(Tree::fromObject($object))),
			GitObject::COMMIT => $this->createCommit(Commit::fromObject($object)),
			default           => throw new GitException('Unknown object type.'),
		};

		if ($id !== $expected) {
			throw new GitException(\sprintf(
				'GitHub stored the %s %s as %s. The content differs, so it was not continued.',
				$object->type,
				$expected,
				$id
			));
		}

		return $id;
	}

	/**
	 * Reads an object from GitHub, rebuilt in git's own form.
	 */
	public function fetch(string $id, string $type): GitObject
	{
		return match ($type) {
			GitObject::BLOB   => GitObject::blob($this->client->getBlob($id)),
			GitObject::TREE   => $this->fetchTree($id),
			GitObject::COMMIT => $this->fetchCommit($id),
			default           => throw new GitException('Unknown object type.'),
		};
	}

	private function createCommit(Commit $commit): string
	{
		if ($commit->extraHeaders !== []) {
			throw new GitException('A signed commit cannot be created again through GitHub\'s API.');
		}

		return $this->client->createCommit(
			$commit->message,
			$commit->tree,
			$commit->parents,
			['name' => $commit->author->name, 'email' => $commit->author->email, 'date' => $commit->author->iso8601()],
			['name' => $commit->committer->name, 'email' => $commit->committer->email, 'date' => $commit->committer->iso8601()]
		);
	}

	/**
	 * @return  array<int, array{path: string, mode: string, type: string, sha: string}>
	 */
	private function treeEntries(Tree $tree): array
	{
		$entries = [];

		foreach ($tree->entries() as $name => $entry) {
			$entries[] = [
				'path' => (string) $name,
				'mode' => $entry['mode'] === Tree::MODE_TREE ? '040000' : $entry['mode'],
				'type' => match ($entry['mode']) {
					Tree::MODE_TREE      => 'tree',
					Tree::MODE_SUBMODULE => 'commit',
					default              => 'blob',
				},
				'sha'  => $entry['id'],
			];
		}

		return $entries;
	}

	private function fetchTree(string $id): GitObject
	{
		$tree = new Tree();

		foreach ($this->client->getTree($id) as $entry) {
			$tree->add($entry['path'], $entry['mode'], $entry['sha']);
		}

		return $tree->toObject();
	}

	private function fetchCommit(string $id): GitObject
	{
		$data = $this->client->getCommit($id);

		$payload   = $data['verification']['payload'] ?? null;
		$signature = $data['verification']['signature'] ?? null;

		// A signed commit (GitHub signs what is made in its web interface): the payload is
		// the commit without its signature header.
		if (\is_string($payload) && \is_string($signature) && $payload !== '' && $signature !== '') {
			$split = strpos($payload, "\n\n");

			if ($split !== false) {
				$object = new GitObject(
					GitObject::COMMIT,
					substr($payload, 0, $split) . "\ngpgsig " . str_replace("\n", "\n ", rtrim($signature, "\n")) . "\n" . substr($payload, $split)
				);

				if ($object->id() === $id) {
					return $object;
				}
			}
		}

		$parents = [];

		foreach ((array) ($data['parents'] ?? []) as $parent) {
			if (\is_array($parent) && isset($parent['sha'])) {
				$parents[] = (string) $parent['sha'];
			}
		}

		/** @var array{name?: string, email?: string, date?: string} $author */
		$author = (array) ($data['author'] ?? []);
		/** @var array{name?: string, email?: string, date?: string} $committer */
		$committer = (array) ($data['committer'] ?? []);

		// The API leaves out two things the id depends on: the newline git stores at the end
		// of the message, and the time zone of the dates (it gives them in UTC). So the
		// commit is rebuilt with each likely form until the id matches: first UTC, then the
		// same time zone for author and committer, then any two. A few thousand hashes at
		// most. If nothing matches, the commit is kept under GitHub's id anyway (writeAs()).
		$tree      = (string) ($data['tree']['sha'] ?? '');
		$message   = (string) ($data['message'] ?? '');
		$messages  = array_unique([$message . "\n", $message]);
		$authorAt  = Signature::fromIso8601($author['name'] ?? '', $author['email'] ?? '', $author['date'] ?? 'now');
		$commitAt  = Signature::fromIso8601($committer['name'] ?? '', $committer['email'] ?? '', $committer['date'] ?? 'now');
		$build     = static fn (string $authorOffset, string $committerOffset, string $text): GitObject => (new Commit(
			$tree,
			$parents,
			new Signature($authorAt->name, $authorAt->email, $authorAt->timestamp, $authorOffset),
			new Signature($commitAt->name, $commitAt->email, $commitAt->timestamp, $committerOffset),
			$text
		))->toObject();

		$first = null;

		foreach ($this->offsetPairs($authorAt->offset, $commitAt->offset) as [$authorOffset, $committerOffset]) {
			foreach ($messages as $text) {
				$object  = $build($authorOffset, $committerOffset, $text);
				$first ??= $object;

				if ($object->id() === $id) {
					return $object;
				}
			}
		}

		return $first ?? $build($authorAt->offset, $commitAt->offset, $message . "\n");
	}

	/**
	 * Time zone pairs for author and committer, most likely first.
	 *
	 * @return  \Generator<int, array{0: string, 1: string}>
	 */
	private function offsetPairs(string $author, string $committer): \Generator
	{
		yield [$author, $committer];

		$offsets = [];

		// Every time zone in use is a whole quarter of an hour from UTC, -12:00 to +14:00.
		for ($minutes = -12 * 60; $minutes <= 14 * 60; $minutes += 15) {
			$offsets[] = \sprintf('%s%02d%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
		}

		foreach ($offsets as $offset) {
			yield [$offset, $offset];
		}

		foreach ($offsets as $authorOffset) {
			foreach ($offsets as $committerOffset) {
				if ($authorOffset !== $committerOffset) {
					yield [$authorOffset, $committerOffset];
				}
			}
		}
	}
}

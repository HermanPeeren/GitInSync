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
 * A bare git repository on disk, read and written without the git program.
 *
 * Objects are written as loose objects (objects/ab/cdef..., zlib-compressed), references
 * as files under refs/. That is plain git: `git log`, `git clone` and `git fsck` work on
 * it. `gc.auto = 0` in its config keeps a git program that does visit it from packing the
 * objects, because packfiles are not read here. Packed references are read.
 */
final class LocalRepository implements ObjectStore, RefStore
{
	public function __construct(private readonly string $path)
	{
	}

	public function path(): string
	{
		return $this->path;
	}

	public function exists(): bool
	{
		return is_file($this->path . '/HEAD') && is_dir($this->path . '/objects');
	}

	/**
	 * Creates the repository, with HEAD pointing at the branch. Does nothing when it exists.
	 */
	public function init(string $branch = 'main'): void
	{
		if ($this->exists()) {
			return;
		}

		foreach (['', '/objects', '/objects/info', '/objects/pack', '/refs', '/refs/heads', '/refs/tags', '/info'] as $dir) {
			$this->makeDir($this->path . $dir);
		}

		$this->writeFile('/HEAD', 'ref: refs/heads/' . $branch . "\n");
		$this->writeFile(
			'/config',
			"[core]\n\trepositoryformatversion = 0\n\tfilemode = false\n\tbare = true\n[gc]\n\tauto = 0\n"
		);
		$this->writeFile('/description', "Content of a Joomla site, written by GitInSync.\n");

		// Inside the web root by choice or by accident: the web server must not hand it out.
		$this->writeFile('/.htaccess', "Require all denied\n");
		$this->writeFile(
			'/web.config',
			"<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization>"
			. "</system.webServer></configuration>\n"
		);
	}

	public function has(string $id): bool
	{
		return GitObject::isId($id) && is_file($this->objectPath($id));
	}

	public function read(string $id, ?string $type = null): GitObject
	{
		if (!$this->has($id)) {
			throw new ObjectNotFound(\sprintf('Object %s is not in the repository.', $id));
		}

		$compressed = file_get_contents($this->objectPath($id));
		$raw        = $compressed === false ? false : @gzuncompress($compressed);

		if ($raw === false) {
			throw new CorruptObject(\sprintf('Object %s cannot be read.', $id));
		}

		$object = GitObject::fromRaw($raw);

		if ($type !== null && $object->type !== $type) {
			throw new CorruptObject(\sprintf('Object %s is a %s, not a %s.', $id, $object->type, $type));
		}

		return $object;
	}

	public function write(GitObject $object): string
	{
		$id = $object->id();
		$this->writeAs($id, $object);

		return $id;
	}

	/**
	 * Stores an object under an id it may not hash to.
	 *
	 * Only for commits fetched from GitHub that can't be rebuilt byte for byte (GitHub's API
	 * doesn't give every commit's raw form). GitInSync then still finds them by their id;
	 * `git fsck` would report them.
	 */
	public function writeAs(string $id, GitObject $object): void
	{
		if ($this->has($id)) {
			return;
		}

		$path = $this->objectPath($id);
		$this->makeDir(\dirname($path));

		$this->atomicWrite($path, (string) gzcompress($object->raw()));
	}

	public function get(string $name): ?string
	{
		$this->checkRefName($name);

		$file = $this->path . '/' . $name;

		if (is_file($file)) {
			$value = trim((string) file_get_contents($file));

			if (str_starts_with($value, 'ref: ')) {
				return $this->get(substr($value, 5));
			}

			return GitObject::isId($value) ? $value : null;
		}

		return $this->packedRefs()[$name] ?? null;
	}

	public function set(string $name, string $id, string|null|false $expected = false): void
	{
		$this->checkRefName($name);

		if (!GitObject::isId($id)) {
			throw new \InvalidArgumentException(\sprintf('"%s" is not a commit id.', $id));
		}

		$file = $this->path . '/' . $name;
		$lock = $file . '.lock';
		$this->makeDir(\dirname($file));

		// Git's own way: whoever creates the .lock file may change the reference.
		$handle = @fopen($lock, 'x');

		if ($handle === false) {
			// A lock left behind by a run that died is not worth more than a minute.
			if (is_file($lock) && filemtime($lock) < time() - 60) {
				@unlink($lock);
				$handle = @fopen($lock, 'x');
			}

			if ($handle === false) {
				throw new RefConflict(\sprintf('%s is being changed by another process.', $name));
			}
		}

		try {
			if ($expected !== false && $this->get($name) !== $expected) {
				throw new RefConflict(\sprintf('%s has moved since it was read.', $name));
			}

			fwrite($handle, $id . "\n");
			fclose($handle);
			$handle = null;

			if (!@rename($lock, $file)) {
				// Windows doesn't replace an existing file by renaming.
				@unlink($file);

				if (!@rename($lock, $file)) {
					throw new GitException(\sprintf('Cannot write %s.', $name));
				}
			}
		} finally {
			if ($handle !== null) {
				fclose($handle);
			}

			if (is_file($lock)) {
				@unlink($lock);
			}
		}
	}

	public function delete(string $name): void
	{
		$this->checkRefName($name);

		$file = $this->path . '/' . $name;

		if (is_file($file)) {
			unlink($file);
		}

		$packed = $this->packedRefs();

		if (isset($packed[$name])) {
			unset($packed[$name]);
			$lines = "# pack-refs with: peeled fully-peeled sorted \n";

			foreach ($packed as $ref => $id) {
				$lines .= $id . ' ' . $ref . "\n";
			}

			$this->atomicWrite($this->path . '/packed-refs', $lines);
		}
	}

	public function list(string $prefix = 'refs/heads/'): array
	{
		$refs = [];

		foreach ($this->packedRefs() as $name => $id) {
			if (str_starts_with($name, $prefix)) {
				$refs[$name] = $id;
			}
		}

		$base = $this->path . '/' . rtrim($prefix, '/');

		if (is_dir($base)) {
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

			foreach ($files as $file) {
				if (!$file instanceof \SplFileInfo || !$file->isFile() || str_ends_with($file->getFilename(), '.lock')) {
					continue;
				}

				$name = str_replace('\\', '/', substr($file->getPathname(), \strlen($this->path) + 1));
				$id   = $this->get($name);

				if ($id !== null) {
					$refs[$name] = $id;
				}
			}
		}

		ksort($refs);

		return $refs;
	}

	/**
	 * The branch HEAD points at, e.g. "main".
	 */
	public function headBranch(): ?string
	{
		$head = @file_get_contents($this->path . '/HEAD');

		if ($head === false || !str_starts_with($head, 'ref: refs/heads/')) {
			return null;
		}

		return trim(substr($head, \strlen('ref: refs/heads/')));
	}

	public function setHeadBranch(string $branch): void
	{
		$this->checkRefName('refs/heads/' . $branch);
		$this->atomicWrite($this->path . '/HEAD', 'ref: refs/heads/' . $branch . "\n");
	}

	/**
	 * @return  array<string, string>
	 */
	private function packedRefs(): array
	{
		$file = $this->path . '/packed-refs';

		if (!is_file($file)) {
			return [];
		}

		$refs = [];

		foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			if ($line === '' || $line[0] === '#' || $line[0] === '^') {
				continue;
			}

			$parts = explode(' ', $line, 2);

			if (\count($parts) === 2 && GitObject::isId($parts[0])) {
				$refs[$parts[1]] = $parts[0];
			}
		}

		return $refs;
	}

	private function objectPath(string $id): string
	{
		return $this->path . '/objects/' . substr($id, 0, 2) . '/' . substr($id, 2);
	}

	private function checkRefName(string $name): void
	{
		// Enough of git's rules to keep a name inside refs/ and out of the way of .lock files.
		if (
			!str_starts_with($name, 'refs/')
			|| str_contains($name, '..')
			|| str_contains($name, '//')
			|| str_ends_with($name, '/')
			|| str_ends_with($name, '.lock')
			|| preg_match('/[\x00-\x20~^:?*\[\\\\]|@\{/', $name)
		) {
			throw new \InvalidArgumentException(\sprintf('Bad reference name "%s".', $name));
		}
	}

	private function makeDir(string $dir): void
	{
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new GitException(\sprintf('Cannot create the directory %s.', $dir));
		}
	}

	private function writeFile(string $relative, string $content): void
	{
		$this->atomicWrite($this->path . $relative, $content);
	}

	private function atomicWrite(string $file, string $content): void
	{
		$temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

		if (@file_put_contents($temp, $content) === false) {
			throw new GitException(\sprintf('Cannot write %s.', $file));
		}

		if (!@rename($temp, $file)) {
			@unlink($file);

			if (!@rename($temp, $file)) {
				@unlink($temp);

				throw new GitException(\sprintf('Cannot write %s.', $file));
			}
		}
	}
}

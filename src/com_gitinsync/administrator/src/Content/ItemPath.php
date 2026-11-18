<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Content;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Where an item lives in the repository: "<type directory>/<uuid>-<slug>/<file>".
 *
 * The uuid is the identity; the slug (alias or name) is there to read and may change.
 */
final class ItemPath
{
	private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

	public function __construct(
		public readonly string $directory,
		public readonly string $uuid,
		public readonly string $folder,
		public readonly string $file
	) {
	}

	public static function parse(string $path): ?self
	{
		if (!preg_match('#^([a-z]+)/((' . self::UUID . ')(?:-[^/]*)?)/([^/]+)$#', $path, $m)) {
			return null;
		}

		return new self($m[1], $m[3], $m[2], $m[4]);
	}

	/**
	 * "articles/<uuid>": the item, whatever its slug.
	 */
	public function key(): string
	{
		return $this->directory . '/' . $this->uuid;
	}

	public static function folder(string $uuid, string $slug): string
	{
		$slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $slug), '-'));
		$slug = substr($slug, 0, 60);

		return $slug === '' ? $uuid : $uuid . '-' . $slug;
	}

	public static function isUuid(string $value): bool
	{
		return (bool) preg_match('#^' . self::UUID . '$#', $value);
	}

	/**
	 * A random (version 4) UUID.
	 */
	public static function newUuid(): string
	{
		$bytes    = random_bytes(16);
		$bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	/**
	 * Groups a flat file map per item.
	 *
	 * @param   array<string, string>  $files  Path => blob id.
	 *
	 * @return  array<string, array{folder: string, files: array<string, string>}>  Item key => its folder and files
	 *          (file name => blob id). Paths that aren't item files are under their own path, with folder "".
	 */
	public static function group(array $files): array
	{
		$items = [];

		foreach ($files as $path => $id) {
			$item = self::parse($path);

			if ($item === null) {
				$items[$path] = ['folder' => '', 'files' => ['' => $id]];

				continue;
			}

			$items[$item->key()]['folder']             = $item->directory . '/' . $item->folder;
			$items[$item->key()]['files'][$item->file] = $id;
		}

		return $items;
	}

	/**
	 * The other way round.
	 *
	 * @param   array<string, array{folder: string, files: array<string, string>}>  $items
	 *
	 * @return  array<string, string>
	 */
	public static function ungroup(array $items): array
	{
		$files = [];

		foreach ($items as $key => $item) {
			foreach ($item['files'] as $file => $id) {
				$files[$item['folder'] === '' ? $key : $item['folder'] . '/' . $file] = $id;
			}
		}

		ksort($files);

		return $files;
	}
}

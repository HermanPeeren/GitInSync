<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Content;

use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A kind of content GitInSync puts in git: articles, categories, ...
 *
 * Turns an item into files and files back into an item. Writing goes through the
 * component's own model, so permissions, workflow, versions and plugins all apply.
 */
interface ContentType
{
	/**
	 * "article", "category", ...: the name in the options and in #__gitinsync_items.
	 */
	public function name(): string;

	/**
	 * The top-level directory in the repository: "articles", ...
	 */
	public function directory(): string;

	/**
	 * "article.json", ...
	 */
	public function mainFile(): string;

	/**
	 * The contexts of content events that are about this type.
	 *
	 * @return  string[]
	 */
	public function contexts(): array;

	/**
	 * The key in the main file that refers to the parent of the same type, or null.
	 */
	public function parentKey(): ?string;

	/**
	 * Local ids after a given id, for the scan.
	 *
	 * @return  int[]
	 */
	public function ids(int $after, int $limit): array;

	/**
	 * Null when the item doesn't exist (any more), or isn't one GitInSync handles.
	 */
	public function export(int $id): ?ExportedItem;

	/**
	 * Saves the item from its files and returns the local id.
	 *
	 * @param   array<string, string>  $files  File name => content.
	 *
	 * @throws  ApplyFailed
	 */
	public function import(array $files, ?int $localId, User $user): int;

	/**
	 * Trashes (or, for what has no trash, deletes) a local item.
	 *
	 * @throws  ApplyFailed
	 */
	public function remove(int $id, User $user): void;

	/**
	 * The item's edit screen, for links from GitInSync's screens.
	 */
	public function editLink(int $id): string;
}

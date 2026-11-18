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
 * One item as files, with what the filters and the commit need to know about it.
 */
final class ExportedItem
{
	/**
	 * @param   array<string, string>  $files     File name inside the item's folder => content.
	 * @param   int                    $authorId  The user who last changed it; 0 when unknown.
	 */
	public function __construct(
		public readonly string $type,
		public readonly int $id,
		public readonly string $uuid,
		public readonly string $folder,
		public readonly string $title,
		public readonly array $files,
		public readonly bool $published,
		public readonly bool $public,
		public readonly bool $trashed,
		public readonly int $authorId,
		public readonly ?string $modified
	) {
	}
}

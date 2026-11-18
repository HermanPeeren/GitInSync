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
 * Tags: tag.json and description.html. The parent is a uuid.
 */
final class TagType extends AbstractTableType
{
	public function name(): string
	{
		return 'tag';
	}

	public function directory(): string
	{
		return 'tags';
	}

	public function mainFile(): string
	{
		return 'tag.json';
	}

	public function contexts(): array
	{
		return ['com_tags.tag'];
	}

	public function editLink(int $id): string
	{
		return 'index.php?option=com_tags&task=tag.edit&id=' . $id;
	}

	protected function table(): string
	{
		return '#__tags';
	}

	protected function conditions(): array
	{
		return [$this->db->quoteName('id') . ' > 1'];
	}

	protected function dropColumns(): array
	{
		return [
			'lft', 'rgt', 'level', 'path', 'checked_out', 'checked_out_time', 'hits', 'version',
			'modified_time', 'modified_user_id', 'asset_id',
		];
	}

	protected function jsonColumns(): array
	{
		return ['params', 'metadata', 'images', 'urls'];
	}

	protected function htmlColumns(): array
	{
		return ['description'];
	}

	protected function references(): array
	{
		return ['parent_id' => ['tag', 'parent', 1]];
	}

	protected function authorColumns(): array
	{
		return ['modified_user_id', 'created_user_id'];
	}

	protected function dateColumns(): array
	{
		return ['modified_time', 'created_time'];
	}

	protected function model(): array
	{
		return ['com_tags', 'Tag'];
	}
}

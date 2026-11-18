<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Content;

use Joomla\CMS\MVC\Model\AdminModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Field groups of articles and of their categories: fieldgroup.json.
 */
final class FieldGroupType extends AbstractTableType
{
	public function name(): string
	{
		return 'fieldgroup';
	}

	public function directory(): string
	{
		return 'fieldgroups';
	}

	public function mainFile(): string
	{
		return 'fieldgroup.json';
	}

	public function contexts(): array
	{
		return ['com_fields.group'];
	}

	public function editLink(int $id): string
	{
		return 'index.php?option=com_fields&task=group.edit&id=' . $id;
	}

	protected function table(): string
	{
		return '#__fields_groups';
	}

	protected function conditions(): array
	{
		$contexts = [$this->db->quote('com_content.article'), $this->db->quote('com_content.categories')];

		return [$this->db->quoteName('context') . ' IN (' . implode(', ', $contexts) . ')'];
	}

	protected function dropColumns(): array
	{
		return ['asset_id', 'checked_out', 'checked_out_time', 'modified', 'modified_by'];
	}

	protected function jsonColumns(): array
	{
		return ['params'];
	}

	protected function slugColumn(): string
	{
		return 'title';
	}

	protected function stateColumn(): string
	{
		return 'state';
	}

	protected function authorColumns(): array
	{
		return ['modified_by', 'created_by'];
	}

	protected function dateColumns(): array
	{
		return ['modified', 'created'];
	}

	protected function model(): array
	{
		return ['com_fields', 'Group'];
	}

	protected function prepareModel(AdminModel $model, array $data): void
	{
		$model->setState('filter.context', (string) ($data['context'] ?? 'com_content.article'));
	}
}

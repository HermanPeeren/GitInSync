<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Content;

use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Custom fields of articles and of their categories: field.json, with the field group and
 * the categories it is assigned to as uuids. The values are stored with the items.
 */
final class FieldType extends AbstractTableType
{
	public function name(): string
	{
		return 'field';
	}

	public function directory(): string
	{
		return 'fields';
	}

	public function mainFile(): string
	{
		return 'field.json';
	}

	public function contexts(): array
	{
		return ['com_fields.field'];
	}

	public function editLink(int $id): string
	{
		return 'index.php?option=com_fields&task=field.edit&id=' . $id;
	}

	protected function table(): string
	{
		return '#__fields';
	}

	protected function conditions(): array
	{
		$contexts = [$this->db->quote('com_content.article'), $this->db->quote('com_content.categories')];

		return [$this->db->quoteName('context') . ' IN (' . implode(', ', $contexts) . ')'];
	}

	protected function dropColumns(): array
	{
		return ['asset_id', 'checked_out', 'checked_out_time', 'modified_time', 'modified_by'];
	}

	protected function jsonColumns(): array
	{
		return ['fieldparams', 'params'];
	}

	protected function references(): array
	{
		return ['group_id' => ['fieldgroup', 'group', 0]];
	}

	protected function slugColumn(): string
	{
		return 'name';
	}

	protected function stateColumn(): string
	{
		return 'state';
	}

	protected function authorColumns(): array
	{
		return ['modified_by', 'created_user_id'];
	}

	protected function dateColumns(): array
	{
		return ['modified_time', 'created_time'];
	}

	protected function model(): array
	{
		return ['com_fields', 'Field'];
	}

	protected function prepareModel(AdminModel $model, array $data): void
	{
		$model->setState('field.context', (string) ($data['context'] ?? 'com_content.article'));
	}

	protected function exportExtras(array $row, array &$data): void
	{
		$id    = (int) $row['id'];
		$query = $this->db->createQuery()
			->select($this->db->quoteName('category_id'))
			->from($this->db->quoteName('#__fields_categories'))
			->where($this->db->quoteName('field_id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		$uuids = [];

		foreach ($this->db->setQuery($query)->loadColumn() ?: [] as $categoryId) {
			// -1 means "no category": assigned to none rather than to all.
			$uuids[] = (int) $categoryId === -1 ? 'none' : $this->map->uuidFor('category', (int) $categoryId);
		}

		sort($uuids);
		$data['categories'] = $uuids;
	}

	protected function importExtras(array $json, array &$data, ?int $localId): void
	{
		$ids = [];

		foreach (\is_array($json['categories'] ?? null) ? $json['categories'] : [] as $uuid) {
			$id = $uuid === 'none' ? -1 : $this->map->idFor('category', (string) $uuid);

			if ($id !== null) {
				$ids[] = $id;
			}
		}

		$data['assigned_cat_ids'] = $ids;
		unset($data['categories']);
	}
}

<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Content;

use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Article categories: category.json and description.html, with tags and custom field
 * values. The parent is a uuid; the nested set is Joomla's business.
 */
final class CategoryType extends AbstractTableType
{
	public function name(): string
	{
		return 'category';
	}

	public function directory(): string
	{
		return 'categories';
	}

	public function mainFile(): string
	{
		return 'category.json';
	}

	public function contexts(): array
	{
		return ['com_categories.category'];
	}

	public function editLink(int $id): string
	{
		return 'index.php?option=com_categories&task=category.edit&extension=com_content&id=' . $id;
	}

	protected function table(): string
	{
		return '#__categories';
	}

	protected function conditions(): array
	{
		return [$this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content')];
	}

	protected function dropColumns(): array
	{
		return [
			'asset_id', 'lft', 'rgt', 'level', 'path', 'checked_out', 'checked_out_time', 'hits', 'version',
			'modified_time', 'modified_user_id',
		];
	}

	protected function jsonColumns(): array
	{
		return ['params', 'metadata'];
	}

	protected function htmlColumns(): array
	{
		return ['description'];
	}

	protected function references(): array
	{
		return ['parent_id' => ['category', 'parent', 1]];
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
		return ['com_categories', 'Category'];
	}

	protected function prepareModel(AdminModel $model, array $data): void
	{
		$model->setState('category.extension', 'com_content');
	}

	protected function exportExtras(array $row, array &$data): void
	{
		$data['tags']   = $this->tagUuids('com_content.category', (int) $row['id']);
		$data['fields'] = $this->fieldValues('com_content.categories', (int) $row['id']);
	}

	protected function importExtras(array $json, array &$data, ?int $localId): void
	{
		$data['extension']  = 'com_content';
		$data['tags']       = $this->tagIds($json['tags'] ?? []);
		$data['com_fields'] = \is_array($json['fields'] ?? null) ? $json['fields'] : [];
		unset($data['fields']);
	}

	protected function authorise(User $user, ?int $localId, array $data): bool
	{
		return $localId !== null
			? $user->authorise('core.edit', 'com_content.category.' . $localId)
			: $user->authorise('core.create', 'com_content');
	}
}

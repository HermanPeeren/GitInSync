<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Content;

use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Articles: article.json, introtext.html and fulltext.html, with tags, custom field values
 * and the featured dates.
 */
final class ArticleType extends AbstractTableType
{
	public function name(): string
	{
		return 'article';
	}

	public function directory(): string
	{
		return 'articles';
	}

	public function mainFile(): string
	{
		return 'article.json';
	}

	public function contexts(): array
	{
		return ['com_content.article', 'com_content.form'];
	}

	public function editLink(int $id): string
	{
		return 'index.php?option=com_content&task=article.edit&id=' . $id;
	}

	protected function table(): string
	{
		return '#__content';
	}

	protected function dropColumns(): array
	{
		return ['asset_id', 'hits', 'checked_out', 'checked_out_time', 'version', 'modified', 'modified_by', 'ordering'];
	}

	protected function jsonColumns(): array
	{
		return ['attribs', 'metadata', 'images', 'urls'];
	}

	protected function htmlColumns(): array
	{
		return ['introtext', 'fulltext'];
	}

	protected function references(): array
	{
		return ['catid' => ['category', 'category', 0]];
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
		return ['com_content', 'Article'];
	}

	protected function exportExtras(array $row, array &$data): void
	{
		$id = (int) $row['id'];

		$data['tags']   = $this->tagUuids('com_content.article', $id);
		$data['fields'] = $this->fieldValues('com_content.article', $id);

		$query = $this->db->createQuery()
			->select($this->db->quoteName(['featured_up', 'featured_down']))
			->from($this->db->quoteName('#__content_frontpage'))
			->where($this->db->quoteName('content_id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		$featured = $this->db->setQuery($query)->loadAssoc();

		$data['featured_up']   = $featured['featured_up'] ?? null;
		$data['featured_down'] = $featured['featured_down'] ?? null;
	}

	protected function importExtras(array $json, array &$data, ?int $localId): void
	{
		$data['tags']       = $this->tagIds($json['tags'] ?? []);
		$data['com_fields'] = \is_array($json['fields'] ?? null) ? $json['fields'] : [];
		unset($data['fields']);
	}

	protected function authorise(User $user, ?int $localId, array $data): bool
	{
		if ($localId !== null) {
			return $user->authorise('core.edit', 'com_content.article.' . $localId)
				|| ($user->authorise('core.edit.own', 'com_content.article.' . $localId) && (int) ($data['created_by'] ?? 0) === (int) $user->id);
		}

		return $user->authorise('core.create', 'com_content.category.' . (int) ($data['catid'] ?? 0));
	}
}

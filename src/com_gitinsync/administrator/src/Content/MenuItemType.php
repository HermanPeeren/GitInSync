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
 * Site menu items: menuitem.json.
 *
 * Local ids are replaced by uuids or names: the menu, the parent, the component (by its
 * name) and the article or category a link points at.
 */
final class MenuItemType extends AbstractTableType
{
	/**
	 * Links whose id is an item GitInSync knows: link pattern => content type.
	 */
	private const LINKS = [
		'#^index\.php\?option=com_content&view=article&id=(\d+)#'  => 'article',
		'#^index\.php\?option=com_content&view=category&(?:layout=[a-z]+&)?id=(\d+)#' => 'category',
	];

	public function name(): string
	{
		return 'menuitem';
	}

	public function directory(): string
	{
		return 'menuitems';
	}

	public function mainFile(): string
	{
		return 'menuitem.json';
	}

	public function contexts(): array
	{
		return ['com_menus.item'];
	}

	public function editLink(int $id): string
	{
		return 'index.php?option=com_menus&task=item.edit&id=' . $id;
	}

	protected function table(): string
	{
		return '#__menu';
	}

	protected function conditions(): array
	{
		return [
			$this->db->quoteName('client_id') . ' = 0',
			$this->db->quoteName('id') . ' > 1',
		];
	}

	protected function dropColumns(): array
	{
		return ['lft', 'rgt', 'level', 'path', 'checked_out', 'checked_out_time'];
	}

	protected function jsonColumns(): array
	{
		return ['params'];
	}

	protected function references(): array
	{
		return ['parent_id' => ['menuitem', 'parent', 1]];
	}

	protected function model(): array
	{
		return ['com_menus', 'Item'];
	}

	protected function prepareModel(AdminModel $model, array $data): void
	{
		$model->setState('item.client_id', 0);
		$model->setState('item.menutype', (string) ($data['menutype'] ?? ''));
	}

	protected function exportExtras(array $row, array &$data): void
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__menu_types'))
			->where($this->db->quoteName('menutype') . ' = :menutype')
			->bind(':menutype', $row['menutype']);
		$menuId = (int) $this->db->setQuery($query)->loadResult();

		$data['menu'] = $menuId > 0 ? $this->map->uuidFor('menu', $menuId) : null;

		$componentId = (int) ($row['component_id'] ?? 0);
		unset($data['component_id']);
		$data['component'] = null;

		if ($componentId > 0) {
			$query = $this->db->createQuery()
				->select($this->db->quoteName('element'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('extension_id') . ' = :id')
				->bind(':id', $componentId, ParameterType::INTEGER);
			$data['component'] = $this->db->setQuery($query)->loadResult() ?: null;
		}

		$data['link_item'] = null;

		foreach (self::LINKS as $pattern => $type) {
			if (preg_match($pattern, (string) $row['link'], $m)) {
				$data['link_item'] = ['type' => $type, 'uuid' => $this->map->uuidFor($type, (int) $m[1])];
				break;
			}
		}
	}

	protected function importExtras(array $json, array &$data, ?int $localId): void
	{
		unset($data['menu'], $data['component'], $data['link_item']);

		if (!empty($json['menu'])) {
			$menuId = $this->map->idFor('menu', (string) $json['menu']);

			if ($menuId === null) {
				throw new ApplyFailed('Its menu is not on this site.');
			}

			$query = $this->db->createQuery()
				->select($this->db->quoteName('menutype'))
				->from($this->db->quoteName('#__menu_types'))
				->where($this->db->quoteName('id') . ' = :id')
				->bind(':id', $menuId, ParameterType::INTEGER);
			$data['menutype'] = (string) $this->db->setQuery($query)->loadResult();
		}

		$data['component_id'] = 0;

		if (!empty($json['component'])) {
			$component = (string) $json['component'];
			$query     = $this->db->createQuery()
				->select($this->db->quoteName('extension_id'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
				->where($this->db->quoteName('element') . ' = :element')
				->bind(':element', $component);
			$data['component_id'] = (int) $this->db->setQuery($query)->loadResult();
		}

		$linkItem = $json['link_item'] ?? null;

		if (\is_array($linkItem) && isset($linkItem['type'], $linkItem['uuid'])) {
			$id = $this->map->idFor((string) $linkItem['type'], (string) $linkItem['uuid']);

			if ($id === null) {
				throw new ApplyFailed(\sprintf('It links to a %s that is not on this site.', (string) $linkItem['type']));
			}

			$data['link'] = preg_replace('#([?&]id=)\d+#', '${1}' . $id, (string) ($data['link'] ?? ''), 1);
		}

		$data['client_id']    = 0;
		$data['menuordering'] = 0;
	}
}

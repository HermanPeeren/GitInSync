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
 * Site menus: menu.json. A menu has no trash, so removing one deletes it, with its items.
 */
final class MenuType extends AbstractTableType
{
	public function name(): string
	{
		return 'menu';
	}

	public function directory(): string
	{
		return 'menus';
	}

	public function mainFile(): string
	{
		return 'menu.json';
	}

	public function contexts(): array
	{
		return ['com_menus.menu'];
	}

	public function editLink(int $id): string
	{
		return 'index.php?option=com_menus&task=menu.edit&id=' . $id;
	}

	protected function table(): string
	{
		return '#__menu_types';
	}

	protected function conditions(): array
	{
		return [$this->db->quoteName('client_id') . ' = 0'];
	}

	protected function dropColumns(): array
	{
		return ['asset_id'];
	}

	protected function slugColumn(): string
	{
		return 'menutype';
	}

	protected function stateColumn(): ?string
	{
		return null;
	}

	protected function accessColumn(): ?string
	{
		return null;
	}

	protected function model(): array
	{
		return ['com_menus', 'Menu'];
	}
}

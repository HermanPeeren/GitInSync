<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Sync;

use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * #__gitinsync_state: small named values (the site's branch, the scan's position, locks,
 * the GitHub repository's visibility).
 */
final class StateStore
{
	private const TABLE = '#__gitinsync_state';

	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	public function get(string $name, ?string $default = null): ?string
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName('value'))
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('name') . ' = :name')
			->bind(':name', $name);

		$value = $this->db->setQuery($query)->loadResult();

		return $value === null ? $default : (string) $value;
	}

	public function set(string $name, ?string $value): void
	{
		$this->delete($name);

		if ($value !== null) {
			$row = (object) ['name' => $name, 'value' => $value];
			$this->db->insertObject(self::TABLE, $row);
		}
	}

	public function delete(string $name): void
	{
		$query = $this->db->createQuery()
			->delete($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('name') . ' = :name')
			->bind(':name', $name);

		$this->db->setQuery($query)->execute();
	}

	/**
	 * Takes a lock unless someone else holds one younger than $seconds.
	 */
	public function lock(string $name, int $seconds): bool
	{
		$now = (string) time();

		try {
			$row = (object) ['name' => 'lock.' . $name, 'value' => $now];
			$this->db->insertObject(self::TABLE, $row);

			return true;
		} catch (\RuntimeException) {
			// Held: take it over only when it has expired.
			$expired = (string) (time() - $seconds);
			$lock    = 'lock.' . $name;
			$query   = $this->db->createQuery()
				->update($this->db->quoteName(self::TABLE))
				->set($this->db->quoteName('value') . ' = :now')
				->where($this->db->quoteName('name') . ' = :name')
				->where($this->db->quoteName('value') . ' < :expired')
				->bind(':now', $now)
				->bind(':name', $lock)
				->bind(':expired', $expired);

			$this->db->setQuery($query)->execute();

			return $this->db->getAffectedRows() === 1;
		}
	}

	public function unlock(string $name): void
	{
		$this->delete('lock.' . $name);
	}
}

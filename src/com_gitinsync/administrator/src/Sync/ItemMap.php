<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Sync;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Yepr\Component\GitInSync\Administrator\Content\ItemPath;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * #__gitinsync_items: which local item a uuid is, and what is waiting to be committed.
 *
 * A uuid is given to an item the first time GitInSync sees it. Items that arrive from git
 * (another branch, another site) keep the uuid they came with and get a local id here.
 */
final class ItemMap
{
	/**
	 * Saved in Joomla: committed on its own, with the editor as author.
	 */
	public const DIRTY_SAVED = 1;

	/**
	 * Found by the scan: committed together with others.
	 */
	public const DIRTY_SCANNED = 2;

	private const TABLE = '#__gitinsync_items';

	/**
	 * @var array<string, string>  "type:id" => uuid
	 */
	private array $uuids = [];

	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	/**
	 * The uuid of a local item, created when it has none yet.
	 */
	public function uuidFor(string $type, int $id): string
	{
		$cacheKey = $type . ':' . $id;

		if (isset($this->uuids[$cacheKey])) {
			return $this->uuids[$cacheKey];
		}

		$row = $this->row($type, $id);

		if ($row !== null) {
			return $this->uuids[$cacheKey] = (string) $row['uuid'];
		}

		$uuid = ItemPath::newUuid();
		$this->insert($type, $id, $uuid, 0);

		return $this->uuids[$cacheKey] = $uuid;
	}

	public function idFor(string $type, string $uuid): ?int
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName('item_id'))
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('type') . ' = :type')
			->where($this->db->quoteName('uuid') . ' = :uuid')
			->bind(':type', $type)
			->bind(':uuid', $uuid);

		$id = $this->db->setQuery($query)->loadResult();

		return $id === null ? null : (int) $id;
	}

	/**
	 * Records that a local item is the item with this uuid (after it came from git).
	 */
	public function link(string $type, int $id, string $uuid): void
	{
		$row = $this->row($type, $id);

		// Another local item that had this uuid (deleted and created again) lets go of it.
		$query = $this->db->createQuery()
			->delete($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('uuid') . ' = :uuid')
			->where('NOT (' . $this->db->quoteName('type') . ' = :type AND ' . $this->db->quoteName('item_id') . ' = :id)')
			->bind(':uuid', $uuid)
			->bind(':type', $type)
			->bind(':id', $id, ParameterType::INTEGER);
		$this->db->setQuery($query)->execute();

		if ($row === null) {
			$this->insert($type, $id, $uuid, 0);
		} else {
			$query = $this->db->createQuery()
				->update($this->db->quoteName(self::TABLE))
				->set($this->db->quoteName('uuid') . ' = :uuid')
				->where($this->db->quoteName('type') . ' = :type')
				->where($this->db->quoteName('item_id') . ' = :id')
				->bind(':uuid', $uuid)
				->bind(':type', $type)
				->bind(':id', $id, ParameterType::INTEGER);
			$this->db->setQuery($query)->execute();
		}

		$this->uuids[$type . ':' . $id] = $uuid;
	}

	public function markDirty(string $type, int $id, int $how = self::DIRTY_SAVED): void
	{
		$row = $this->row($type, $id);

		if ($row === null) {
			$this->insert($type, $id, ItemPath::newUuid(), $how);

			return;
		}

		// A save outranks a scan: it gets its own commit.
		if ((int) $row['dirty'] === self::DIRTY_SAVED && $how === self::DIRTY_SCANNED) {
			return;
		}

		$this->update($type, $id, ['dirty' => $how]);
	}

	/**
	 * @param   array<int, array{type: string, id: int}>  $items
	 */
	public function markClean(array $items): void
	{
		foreach ($items as $item) {
			$this->update($item['type'], $item['id'], ['dirty' => 0]);
		}
	}

	/**
	 * @return  array<int, array{type: string, id: int, uuid: string, dirty: int}>  Saved ones first, oldest first.
	 */
	public function dirty(int $limit, ?int $how = null): array
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName(['type', 'item_id', 'uuid', 'dirty']))
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('dirty') . ' > 0')
			->order($this->db->quoteName('dirty') . ' ASC, ' . $this->db->quoteName('id') . ' ASC');

		if ($how !== null) {
			$query->where($this->db->quoteName('dirty') . ' = :how')->bind(':how', $how, ParameterType::INTEGER);
		}

		$rows = $this->db->setQuery($query, 0, $limit)->loadAssocList() ?: [];

		return array_map(
			static fn (array $row): array => [
				'type'  => (string) $row['type'],
				'id'    => (int) $row['item_id'],
				'uuid'  => (string) $row['uuid'],
				'dirty' => (int) $row['dirty'],
			],
			$rows
		);
	}

	public function dirtyCount(): int
	{
		$query = $this->db->createQuery()
			->select('COUNT(*)')
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('dirty') . ' > 0');

		return (int) $this->db->setQuery($query)->loadResult();
	}

	public function isExcluded(string $type, int $id): bool
	{
		return (bool) ($this->row($type, $id)['excluded'] ?? false);
	}

	public function setExcluded(string $type, int $id, bool $excluded): void
	{
		$this->uuidFor($type, $id);
		$this->update($type, $id, ['excluded' => $excluded ? 1 : 0]);
	}

	/**
	 * @return  array<string, int>  uuid => local id, for all items of a type.
	 */
	public function all(string $type): array
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName(['uuid', 'item_id']))
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('type') . ' = :type')
			->bind(':type', $type);

		$map = [];

		foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
			$map[(string) $row['uuid']] = (int) $row['item_id'];
		}

		return $map;
	}

	/**
	 * @return  array{type: string, id: int}|null
	 */
	public function byUuid(string $uuid): ?array
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName(['type', 'item_id']))
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('uuid') . ' = :uuid')
			->bind(':uuid', $uuid);

		$row = $this->db->setQuery($query)->loadAssoc();

		return $row ? ['type' => (string) $row['type'], 'id' => (int) $row['item_id']] : null;
	}

	/**
	 * @return  array<string, mixed>|null
	 */
	private function row(string $type, int $id): ?array
	{
		$query = $this->db->createQuery()
			->select('*')
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('type') . ' = :type')
			->where($this->db->quoteName('item_id') . ' = :id')
			->bind(':type', $type)
			->bind(':id', $id, ParameterType::INTEGER);

		return $this->db->setQuery($query)->loadAssoc() ?: null;
	}

	private function insert(string $type, int $id, string $uuid, int $dirty): void
	{
		$row = (object) ['type' => $type, 'item_id' => $id, 'uuid' => $uuid, 'dirty' => $dirty, 'excluded' => 0];
		$this->db->insertObject(self::TABLE, $row);
		$this->uuids[$type . ':' . $id] = $uuid;
	}

	/**
	 * @param   array<string, int>  $values
	 */
	private function update(string $type, int $id, array $values): void
	{
		$query = $this->db->createQuery()
			->update($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('type') . ' = :type')
			->where($this->db->quoteName('item_id') . ' = :id')
			->bind(':type', $type)
			->bind(':id', $id, ParameterType::INTEGER);

		foreach ($values as $column => $value) {
			$query->set($this->db->quoteName($column) . ' = ' . (int) $value);
		}

		$this->db->setQuery($query)->execute();
	}
}

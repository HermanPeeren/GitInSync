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

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * #__gitinsync_operations: restores, reverts, cherry-picks, merges and branch switches,
 * with their conflicts and how far applying them has got.
 */
final class OperationStore
{
	public const CONFLICTS = 'conflicts';
	public const APPLYING  = 'applying';
	public const DONE      = 'done';
	public const FAILED    = 'failed';
	public const ABORTED   = 'aborted';

	private const TABLE = '#__gitinsync_operations';

	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	/**
	 * @param   array<string, mixed>  $data
	 */
	public function insert(string $kind, string $status, string $branch, string $title, array $data, int $userId): int
	{
		$row = (object) [
			'kind'       => $kind,
			'status'     => $status,
			'branch'     => $branch,
			'title'      => mb_substr($title, 0, 255),
			'data'       => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			'message'    => '',
			'created'    => gmdate('Y-m-d H:i:s'),
			'created_by' => $userId,
		];

		$this->db->insertObject(self::TABLE, $row);

		return (int) $this->db->insertid();
	}

	/**
	 * @return  array{
	 *     id: int, kind: string, status: string, branch: string, title: string,
	 *     message: string, created: string, created_by: int, data: array<string, mixed>
	 * }|null
	 */
	public function get(int $id): ?array
	{
		$query = $this->db->createQuery()
			->select('*')
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return null;
		}

		$data = json_decode((string) $row['data'], true);

		return [
			'id'         => (int) $row['id'],
			'kind'       => (string) $row['kind'],
			'status'     => (string) $row['status'],
			'branch'     => (string) $row['branch'],
			'title'      => (string) $row['title'],
			'message'    => (string) $row['message'],
			'created'    => (string) $row['created'],
			'created_by' => (int) $row['created_by'],
			'data'       => \is_array($data) ? $data : [],
		];
	}

	/**
	 * @param   array<string, mixed>|null  $data
	 */
	public function update(int $id, string $status, ?array $data = null, ?string $message = null): void
	{
		$query = $this->db->createQuery()
			->update($this->db->quoteName(self::TABLE))
			->set($this->db->quoteName('status') . ' = :status')
			->where($this->db->quoteName('id') . ' = :id')
			->bind(':status', $status)
			->bind(':id', $id, ParameterType::INTEGER);

		if ($data !== null) {
			$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$query->set($this->db->quoteName('data') . ' = :data')->bind(':data', $json);
		}

		if ($message !== null) {
			$message = mb_substr($message, 0, 2000);
			$query->set($this->db->quoteName('message') . ' = :message')->bind(':message', $message);
		}

		$this->db->setQuery($query)->execute();
	}

	/**
	 * @return  array<int, array{id: int, kind: string, status: string, branch: string, title: string, message: string, created: string}>
	 */
	public function recent(int $limit = 20): array
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName(['id', 'kind', 'status', 'branch', 'title', 'message', 'created']))
			->from($this->db->quoteName(self::TABLE))
			->order($this->db->quoteName('id') . ' DESC');

		return array_map(
			static fn (array $row): array => [
				'id'      => (int) $row['id'],
				'kind'    => (string) $row['kind'],
				'status'  => (string) $row['status'],
				'branch'  => (string) $row['branch'],
				'title'   => (string) $row['title'],
				'message' => (string) $row['message'],
				'created' => (string) $row['created'],
			],
			$this->db->setQuery($query, 0, $limit)->loadAssocList() ?: []
		);
	}

	/**
	 * The operation that is not finished yet, if any: only one at a time.
	 */
	public function open(): ?int
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName(self::TABLE))
			->whereIn($this->db->quoteName('status'), [self::CONFLICTS, self::APPLYING], ParameterType::STRING)
			->order($this->db->quoteName('id') . ' DESC');

		$id = $this->db->setQuery($query, 0, 1)->loadResult();

		return $id === null ? null : (int) $id;
	}
}

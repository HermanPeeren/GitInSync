<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Content;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Yepr\Component\GitInSync\Administrator\Sync\ItemMap;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A content type that is one row in one table, described by a few settings.
 *
 * Export: the row minus volatile columns; JSON columns decoded so each key is a line;
 * HTML columns as files of their own; references to other items as uuids.
 * Import: the other way round, then saved through the component's model.
 */
abstract class AbstractTableType implements ContentType
{
	/**
	 * @param   \Closure(string, string): AdminModel  $models  Component and model name => a fresh model, ignoring the request.
	 */
	public function __construct(
		protected readonly DatabaseInterface $db,
		protected readonly ItemMap $map,
		protected readonly \Closure $models,
		protected readonly UserFactoryInterface $users
	) {
	}

	abstract protected function table(): string;

	/**
	 * Columns that change without the content changing (hits, checked out, ...), or that
	 * are derived (nested set columns), or local to the site (asset id).
	 *
	 * @return  string[]
	 */
	abstract protected function dropColumns(): array;

	/**
	 * @return  string[]
	 */
	protected function jsonColumns(): array
	{
		return [];
	}

	/**
	 * Columns stored as "<column>.html" files.
	 *
	 * @return  string[]
	 */
	protected function htmlColumns(): array
	{
		return [];
	}

	/**
	 * Column => [content type, key in the JSON, value that means "none"].
	 *
	 * @return  array<string, array{0: string, 1: string, 2: int}>
	 */
	protected function references(): array
	{
		return [];
	}

	protected function slugColumn(): string
	{
		return 'alias';
	}

	protected function titleColumn(): string
	{
		return 'title';
	}

	protected function stateColumn(): ?string
	{
		return 'published';
	}

	protected function accessColumn(): ?string
	{
		return 'access';
	}

	/**
	 * Who last changed it: the first of these columns that has a user.
	 *
	 * @return  string[]
	 */
	protected function authorColumns(): array
	{
		return [];
	}

	/**
	 * When it last changed: the first of these columns that has a date.
	 *
	 * @return  string[]
	 */
	protected function dateColumns(): array
	{
		return [];
	}

	/**
	 * Conditions on the table that make a row one of this type.
	 *
	 * @return  string[]  SQL conditions, already quoted.
	 */
	protected function conditions(): array
	{
		return [];
	}

	/**
	 * @return  array{0: string, 1: string}  Component and model name.
	 */
	abstract protected function model(): array;

	/**
	 * Adds what isn't in the row: tags, custom field values, assignments.
	 *
	 * @param   array<string, mixed>  $row
	 * @param   array<string, mixed>  $data
	 */
	protected function exportExtras(array $row, array &$data): void
	{
	}

	/**
	 * Turns those back into what the model's save() takes.
	 *
	 * @param   array<string, mixed>  $json
	 * @param   array<string, mixed>  $data
	 */
	protected function importExtras(array $json, array &$data, ?int $localId): void
	{
	}

	/**
	 * Prepares the model before saving (state it would otherwise read from the request).
	 *
	 * @param   array<string, mixed>  $data
	 */
	protected function prepareModel(AdminModel $model, array $data): void
	{
	}

	/**
	 * @param   array<string, mixed>  $data
	 */
	protected function authorise(User $user, ?int $localId, array $data): bool
	{
		$component = $this->model()[0];

		return $localId !== null
			? $user->authorise('core.edit', $component) || $user->authorise('core.edit.own', $component)
			: $user->authorise('core.create', $component);
	}

	public function parentKey(): ?string
	{
		foreach ($this->references() as [$type, $key]) {
			if ($type === $this->name()) {
				return $key;
			}
		}

		return null;
	}

	public function ids(int $after, int $limit): array
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName($this->table()))
			->where($this->db->quoteName('id') . ' > :after')
			->bind(':after', $after, ParameterType::INTEGER)
			->order($this->db->quoteName('id'));

		foreach ($this->conditions() as $condition) {
			$query->where($condition);
		}

		return array_map('intval', $this->db->setQuery($query, 0, $limit)->loadColumn() ?: []);
	}

	/**
	 * @return  array<string, mixed>|null
	 */
	protected function row(int $id): ?array
	{
		$query = $this->db->createQuery()
			->select('*')
			->from($this->db->quoteName($this->table()))
			->where($this->db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		foreach ($this->conditions() as $condition) {
			$query->where($condition);
		}

		return $this->db->setQuery($query)->loadAssoc() ?: null;
	}

	public function export(int $id): ?ExportedItem
	{
		$row = $this->row($id);

		if ($row === null) {
			return null;
		}

		$uuid = $this->map->uuidFor($this->name(), $id);
		$data = $row;

		unset($data['id']);

		foreach ($this->dropColumns() as $column) {
			unset($data[$column]);
		}

		$files = [];

		foreach ($this->htmlColumns() as $column) {
			$html = str_replace("\r\n", "\n", (string) ($row[$column] ?? ''));
			unset($data[$column]);

			if ($html !== '') {
				$files[$column . '.html'] = $html;
			}
		}

		foreach ($this->jsonColumns() as $column) {
			if (\array_key_exists($column, $data)) {
				$data[$column] = CanonicalJson::column($data[$column] === null ? null : (string) $data[$column]);
			}
		}

		foreach ($this->references() as $column => [$type, $key, $none]) {
			$value = (int) ($row[$column] ?? 0);
			unset($data[$column]);
			$data[$key] = $value === $none || $value === 0 ? null : $this->map->uuidFor($type, $value);
		}

		$this->exportExtras($row, $data);
		$data['uuid'] = $uuid;

		$files[$this->mainFile()] = CanonicalJson::encode($data);

		$state  = $this->stateColumn() === null ? 1 : (int) ($row[$this->stateColumn()] ?? 0);
		$access = $this->accessColumn() === null ? 1 : (int) ($row[$this->accessColumn()] ?? 1);

		return new ExportedItem(
			$this->name(),
			$id,
			$uuid,
			$this->directory() . '/' . ItemPath::folder($uuid, (string) ($row[$this->slugColumn()] ?? '')),
			(string) ($row[$this->titleColumn()] ?? ''),
			$files,
			$state === 1,
			$access === 1,
			$state === -2,
			$this->author($row),
			$this->date($row)
		);
	}

	public function import(array $files, ?int $localId, User $user): int
	{
		$json = CanonicalJson::decode($files[$this->mainFile()] ?? throw new ApplyFailed(\sprintf('%s is missing.', $this->mainFile())));
		$uuid = (string) ($json['uuid'] ?? '');

		if (!ItemPath::isUuid($uuid)) {
			throw new ApplyFailed(\sprintf('%s has no uuid.', $this->mainFile()));
		}

		if ($localId !== null && $this->row($localId) === null) {
			$localId = null;
		}

		$data = $json;
		unset($data['uuid']);

		foreach ($this->dropColumns() as $column) {
			unset($data[$column]);
		}

		foreach ($this->htmlColumns() as $column) {
			// The text filter of the user who applies it, as in a normal save: content in git
			// can come from anyone who can push to the repository.
			$data[$column] = ComponentHelper::filterText($files[$column . '.html'] ?? '');
		}

		foreach ($this->jsonColumns() as $column) {
			if (\array_key_exists($column, $data)) {
				$data[$column] = CanonicalJson::toColumn($data[$column]);
			}
		}

		foreach ($this->references() as $column => [$type, $key, $none]) {
			$refUuid = $json[$key] ?? null;
			unset($data[$key]);

			if ($refUuid === null || $refUuid === '') {
				$data[$column] = $none;

				continue;
			}

			$refId = $this->map->idFor($type, (string) $refUuid);

			if ($refId === null) {
				throw new ApplyFailed(\sprintf('It refers to a %s (%s) that is not on this site.', $type, $refUuid));
			}

			$data[$column] = $refId;
		}

		foreach ($this->authorColumns() as $column) {
			if (isset($data[$column]) && !$this->userExists((int) $data[$column])) {
				unset($data[$column]);
			}
		}

		$this->importExtras($json, $data, $localId);

		$data['id'] = $localId ?? 0;

		if (!$this->authorise($user, $localId, $data)) {
			throw new ApplyFailed('You are not allowed to save it.');
		}

		if ($localId !== null && $this->isCheckedOut($localId, $user)) {
			throw new ApplyFailed('It is checked out by someone else.');
		}

		[$component, $name] = $this->model();
		$model              = ($this->models)($component, $name);
		$this->prepareModel($model, $data);

		if (!$model->save($data)) {
			throw new ApplyFailed($model->getError() ?: 'The model refused to save it.');
		}

		$id = (int) $model->getState($model->getName() . '.id');

		if ($id <= 0) {
			throw new ApplyFailed('Saved, but the new id is unknown.');
		}

		$this->map->link($this->name(), $id, $uuid);

		return $id;
	}

	public function remove(int $id, User $user): void
	{
		$row = $this->row($id);

		if ($row === null) {
			return;
		}

		[$component, $name] = $this->model();

		if (!$user->authorise('core.edit.state', $component)) {
			throw new ApplyFailed('You are not allowed to trash it.');
		}

		$model = ($this->models)($component, $name);
		$pks   = [$id];

		if ($this->stateColumn() === null) {
			if (!$model->delete($pks)) {
				throw new ApplyFailed($model->getError() ?: 'The model refused to delete it.');
			}

			return;
		}

		if ((int) $row[$this->stateColumn()] !== -2 && !$model->publish($pks, -2)) {
			throw new ApplyFailed($model->getError() ?: 'The model refused to trash it.');
		}
	}

	/**
	 * @param   array<string, mixed>  $row
	 */
	private function author(array $row): int
	{
		foreach ($this->authorColumns() as $column) {
			if ((int) ($row[$column] ?? 0) > 0) {
				return (int) $row[$column];
			}
		}

		return 0;
	}

	/**
	 * @param   array<string, mixed>  $row
	 */
	private function date(array $row): ?string
	{
		foreach ($this->dateColumns() as $column) {
			$value = (string) ($row[$column] ?? '');

			if ($value !== '' && !str_starts_with($value, '0000')) {
				return $value;
			}
		}

		return null;
	}

	private function userExists(int $id): bool
	{
		return $id > 0 && $this->users->loadUserById($id)->id === $id;
	}

	private function isCheckedOut(int $id, User $user): bool
	{
		$row = $this->row($id);

		return $row !== null && (int) ($row['checked_out'] ?? 0) > 0 && (int) $row['checked_out'] !== (int) $user->id;
	}

	/**
	 * Tags of an item, as uuids.
	 *
	 * @return  string[]
	 */
	protected function tagUuids(string $typeAlias, int $id): array
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName('tag_id'))
			->from($this->db->quoteName('#__contentitem_tag_map'))
			->where($this->db->quoteName('type_alias') . ' = :alias')
			->where($this->db->quoteName('content_item_id') . ' = :id')
			->bind(':alias', $typeAlias)
			->bind(':id', $id, ParameterType::INTEGER);

		$uuids = array_map(fn ($tagId): string => $this->map->uuidFor('tag', (int) $tagId), $this->db->setQuery($query)->loadColumn() ?: []);
		sort($uuids);

		return $uuids;
	}

	/**
	 * Tag uuids back into local tag ids; tags that aren't on this site are left out.
	 *
	 * @param   mixed  $uuids
	 *
	 * @return  int[]
	 */
	protected function tagIds(mixed $uuids): array
	{
		$ids = [];

		foreach (\is_array($uuids) ? $uuids : [] as $uuid) {
			$id = $this->map->idFor('tag', (string) $uuid);

			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Custom field values of an item: field name => value (a list for multiple values).
	 *
	 * @return  array<string, mixed>
	 */
	protected function fieldValues(string $context, int $id): array
	{
		$itemId = (string) $id;
		$query  = $this->db->createQuery()
			->select($this->db->quoteName(['f.name', 'v.value']))
			->from($this->db->quoteName('#__fields_values', 'v'))
			->join('INNER', $this->db->quoteName('#__fields', 'f'), $this->db->quoteName('f.id') . ' = ' . $this->db->quoteName('v.field_id'))
			->where($this->db->quoteName('f.context') . ' = :context')
			->where($this->db->quoteName('v.item_id') . ' = :id')
			->bind(':context', $context)
			->bind(':id', $itemId)
			->order($this->db->quoteName('f.name'));

		$values = [];

		foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
			$name = (string) $row['name'];

			if (!\array_key_exists($name, $values)) {
				$values[$name] = $row['value'];
			} elseif (\is_array($values[$name])) {
				$values[$name][] = $row['value'];
			} else {
				$values[$name] = [$values[$name], $row['value']];
			}
		}

		return $values;
	}
}

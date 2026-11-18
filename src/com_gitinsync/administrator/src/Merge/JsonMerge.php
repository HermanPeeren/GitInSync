<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Merge;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Three-way merge of JSON documents per key.
 *
 * One side changing the title and the other the category of the same article is no
 * conflict. Objects are merged key by key, at every level; lists and scalars are values:
 * changed on both sides to different values is a conflict, which keeps our value.
 */
final class JsonMerge
{
	/**
	 * @var array<int, array{path: string, base: mixed, ours: mixed, theirs: mixed}>
	 */
	private array $conflicts = [];

	/**
	 * @param   array<mixed>  $base
	 * @param   array<mixed>  $ours
	 * @param   array<mixed>  $theirs
	 *
	 * @return  array{result: array<mixed>, conflicts: array<int, array{path: string, base: mixed, ours: mixed, theirs: mixed}>}
	 */
	public static function merge(array $base, array $ours, array $theirs): array
	{
		$merger = new self();
		$result = $merger->value('', true, $base, true, $ours, true, $theirs);

		return ['result' => \is_array($result['value']) ? $result['value'] : [], 'conflicts' => $merger->conflicts];
	}

	/**
	 * @return  array{present: bool, value: mixed}
	 */
	private function value(string $path, bool $inBase, mixed $base, bool $inOurs, mixed $ours, bool $inTheirs, mixed $theirs): array
	{
		if ($inOurs === $inTheirs && $ours === $theirs) {
			return ['present' => $inOurs, 'value' => $ours];
		}

		if ($inBase === $inOurs && $base === $ours) {
			return ['present' => $inTheirs, 'value' => $theirs];
		}

		if ($inBase === $inTheirs && $base === $theirs) {
			return ['present' => $inOurs, 'value' => $ours];
		}

		if (self::isObject($ours) && self::isObject($theirs) && (!$inBase || self::isObject($base))) {
			/** @var array<string, mixed> $ours */
			/** @var array<string, mixed> $theirs */
			$base   = \is_array($base) ? $base : [];
			$result = [];

			foreach (array_unique(array_merge(array_keys($base), array_keys($ours), array_keys($theirs))) as $key) {
				$merged = $this->value(
					$path === '' ? (string) $key : $path . '.' . $key,
					\array_key_exists($key, $base),
					$base[$key] ?? null,
					\array_key_exists($key, $ours),
					$ours[$key] ?? null,
					\array_key_exists($key, $theirs),
					$theirs[$key] ?? null
				);

				if ($merged['present']) {
					$result[$key] = $merged['value'];
				}
			}

			return ['present' => true, 'value' => $result];
		}

		$this->conflicts[] = [
			'path'   => $path,
			'base'   => $inBase ? $base : null,
			'ours'   => $inOurs ? $ours : null,
			'theirs' => $inTheirs ? $theirs : null,
		];

		return ['present' => $inOurs, 'value' => $ours];
	}

	private static function isObject(mixed $value): bool
	{
		return \is_array($value) && ($value === [] || !array_is_list($value));
	}
}

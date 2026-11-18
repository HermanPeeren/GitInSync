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
 * JSON that is byte for byte the same for the same data.
 *
 * Keys sorted at every level, four spaces, "\n", no escaped slashes or Unicode, and a
 * final newline. That way git shows only real changes, each on its own line.
 */
final class CanonicalJson
{
	public static function encode(mixed $data): string
	{
		return json_encode(
			self::sort($data),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
		) . "\n";
	}

	/**
	 * @return  array<mixed>
	 */
	public static function decode(string $json): array
	{
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		if (!\is_array($data)) {
			throw new \UnexpectedValueException('Expected a JSON object.');
		}

		return $data;
	}

	/**
	 * Decodes a JSON column (attribs, params, ...) so its keys become part of the document.
	 * What isn't valid JSON stays a string.
	 */
	public static function column(?string $value): mixed
	{
		if ($value === null || $value === '') {
			return $value;
		}

		$decoded = json_decode($value, true);

		return \is_array($decoded) ? $decoded : $value;
	}

	/**
	 * The other way round: back into a column.
	 */
	public static function toColumn(mixed $value): ?string
	{
		if ($value === null || \is_string($value)) {
			return $value;
		}

		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	private static function sort(mixed $data): mixed
	{
		if (!\is_array($data)) {
			return $data;
		}

		if (!array_is_list($data)) {
			ksort($data, SORT_STRING);
		}

		return array_map(self::sort(...), $data);
	}
}

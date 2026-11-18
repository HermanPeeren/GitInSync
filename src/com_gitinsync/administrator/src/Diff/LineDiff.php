<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Diff;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Line-by-line differences between two texts.
 *
 * A longest common subsequence after taking off the common start and end. Content items
 * are short enough for that; very long texts fall back to "all lines changed" rather than
 * running out of memory.
 */
final class LineDiff
{
	public const EQUAL  = ' ';
	public const DELETE = '-';
	public const INSERT = '+';

	/**
	 * Above this many cells in the LCS table, the middle is reported as replaced.
	 */
	private const MAX_CELLS = 250_000;

	/**
	 * @return  string[]  Lines without their line ends. An empty text has no lines.
	 */
	public static function lines(string $text): array
	{
		if ($text === '') {
			return [];
		}

		$lines = explode("\n", str_replace("\r\n", "\n", $text));

		// A final newline ends the last line rather than starting an empty one.
		if (end($lines) === '') {
			array_pop($lines);
		}

		return $lines;
	}

	/**
	 * @param   string[]  $a
	 * @param   string[]  $b
	 *
	 * @return  array<int, array{0: string, 1: string}>  Operation (EQUAL, DELETE, INSERT) and line.
	 */
	public static function diff(array $a, array $b): array
	{
		$a = array_values($a);
		$b = array_values($b);

		$start = 0;
		$endA  = \count($a);
		$endB  = \count($b);

		while ($start < $endA && $start < $endB && $a[$start] === $b[$start]) {
			$start++;
		}

		while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
			$endA--;
			$endB--;
		}

		$ops = [];

		for ($i = 0; $i < $start; $i++) {
			$ops[] = [self::EQUAL, $a[$i]];
		}

		array_push($ops, ...self::middle(\array_slice($a, $start, $endA - $start), \array_slice($b, $start, $endB - $start)));

		for ($i = $endA, $n = \count($a); $i < $n; $i++) {
			$ops[] = [self::EQUAL, $a[$i]];
		}

		return $ops;
	}

	/**
	 * The matching lines as pairs of indexes, in order: what diff3 builds on.
	 *
	 * @param   string[]  $a
	 * @param   string[]  $b
	 *
	 * @return  array<int, array{0: int, 1: int}>
	 */
	public static function matches(array $a, array $b): array
	{
		$pairs = [];
		$i     = 0;
		$j     = 0;

		foreach (self::diff($a, $b) as [$op]) {
			if ($op === self::EQUAL) {
				$pairs[] = [$i, $j];
				$i++;
				$j++;
			} elseif ($op === self::DELETE) {
				$i++;
			} else {
				$j++;
			}
		}

		return $pairs;
	}

	/**
	 * A unified diff with a few lines of context, for showing a change.
	 */
	public static function unified(string $old, string $new, int $context = 3): string
	{
		$ops = self::diff(self::lines($old), self::lines($new));
		$out = [];
		$n   = \count($ops);

		$show = array_fill(0, $n, false);

		foreach ($ops as $k => [$op]) {
			if ($op !== self::EQUAL) {
				for ($c = max(0, $k - $context); $c <= min($n - 1, $k + $context); $c++) {
					$show[$c] = true;
				}
			}
		}

		$skipped = false;

		foreach ($ops as $k => [$op, $line]) {
			if (!$show[$k]) {
				$skipped = true;

				continue;
			}

			if ($skipped && $out !== []) {
				$out[] = '…';
			}

			$skipped = false;
			$out[]   = $op . ' ' . $line;
		}

		return implode("\n", $out);
	}

	/**
	 * @param   string[]  $a
	 * @param   string[]  $b
	 *
	 * @return  array<int, array{0: string, 1: string}>
	 */
	private static function middle(array $a, array $b): array
	{
		$n = \count($a);
		$m = \count($b);

		if ($n === 0 || $m === 0 || $n * $m > self::MAX_CELLS) {
			return array_merge(
				array_map(static fn (string $line): array => [self::DELETE, $line], $a),
				array_map(static fn (string $line): array => [self::INSERT, $line], $b)
			);
		}

		// lengths[i][j]: length of the LCS of a[i..] and b[j..].
		$lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

		for ($i = $n - 1; $i >= 0; $i--) {
			for ($j = $m - 1; $j >= 0; $j--) {
				$lengths[$i][$j] = $a[$i] === $b[$j]
					? $lengths[$i + 1][$j + 1] + 1
					: max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
			}
		}

		$ops = [];
		$i   = 0;
		$j   = 0;

		while ($i < $n && $j < $m) {
			if ($a[$i] === $b[$j]) {
				$ops[] = [self::EQUAL, $a[$i]];
				$i++;
				$j++;
			} elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
				$ops[] = [self::DELETE, $a[$i]];
				$i++;
			} else {
				$ops[] = [self::INSERT, $b[$j]];
				$j++;
			}
		}

		for (; $i < $n; $i++) {
			$ops[] = [self::DELETE, $a[$i]];
		}

		for (; $j < $m; $j++) {
			$ops[] = [self::INSERT, $b[$j]];
		}

		return $ops;
	}
}

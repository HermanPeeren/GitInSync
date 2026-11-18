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
 * Three-way merge of texts, line by line, as `git merge-file` does.
 *
 * Each side's changes against the common base are hunks: a range of base lines and what
 * replaced them. Hunks of one side that don't touch a hunk of the other side are taken
 * over. Where hunks of both sides overlap, the result is the same change (taken once) or
 * a conflict, written between the usual markers.
 */
final class Diff3
{
	/**
	 * @return  array{text: string, conflicts: int}
	 */
	public static function merge(
		string $base,
		string $ours,
		string $theirs,
		string $oursLabel = 'ours',
		string $theirsLabel = 'theirs'
	): array {
		if ($ours === $theirs || $theirs === $base) {
			return ['text' => $ours, 'conflicts' => 0];
		}

		if ($ours === $base) {
			return ['text' => $theirs, 'conflicts' => 0];
		}

		$baseLines = LineDiff::lines($base);
		$hunks     = array_merge(
			self::hunks($baseLines, LineDiff::lines($ours), 'ours'),
			self::hunks($baseLines, LineDiff::lines($theirs), 'theirs')
		);

		usort($hunks, static fn (array $x, array $y): int => [$x['start'], $x['end']] <=> [$y['start'], $y['end']]);

		$out       = [];
		$conflicts = 0;
		$pos       = 0;
		$count     = \count($hunks);

		for ($k = 0; $k < $count;) {
			$group = [$hunks[$k]];
			$start = $hunks[$k]['start'];
			$end   = $hunks[$k]['end'];
			$k++;

			while ($k < $count && ($hunks[$k]['start'] < $end || $hunks[$k]['start'] === $start)) {
				$group[] = $hunks[$k];
				$end     = max($end, $hunks[$k]['end']);
				$k++;
			}

			for (; $pos < $start; $pos++) {
				$out[] = $baseLines[$pos];
			}

			$sides = array_unique(array_column($group, 'side'));

			if (\count($sides) === 1) {
				foreach (self::apply($baseLines, $start, $end, $group) as $line) {
					$out[] = $line;
				}
			} else {
				$oursResult   = self::apply($baseLines, $start, $end, array_filter($group, static fn (array $h): bool => $h['side'] === 'ours'));
				$theirsResult = self::apply($baseLines, $start, $end, array_filter($group, static fn (array $h): bool => $h['side'] === 'theirs'));

				if ($oursResult === $theirsResult) {
					array_push($out, ...$oursResult);
				} else {
					$conflicts++;
					$out[] = '<<<<<<< ' . $oursLabel;
					array_push($out, ...$oursResult);
					$out[] = '||||||| base';
					array_push($out, ...\array_slice($baseLines, $start, $end - $start));
					$out[] = '=======';
					array_push($out, ...$theirsResult);
					$out[] = '>>>>>>> ' . $theirsLabel;
				}
			}

			$pos = $end;
		}

		for ($n = \count($baseLines); $pos < $n; $pos++) {
			$out[] = $baseLines[$pos];
		}

		$text = implode("\n", $out);

		// Keep the final newline when the sides have one.
		if ($out !== [] && (str_ends_with($ours, "\n") || str_ends_with($theirs, "\n"))) {
			$text .= "\n";
		}

		return ['text' => $text, 'conflicts' => $conflicts];
	}

	/**
	 * @param   string[]  $base
	 * @param   string[]  $other
	 *
	 * @return  array<int, array{start: int, end: int, lines: string[], side: string}>
	 */
	private static function hunks(array $base, array $other, string $side): array
	{
		$hunks   = [];
		$i       = 0;
		$current = null;

		foreach (LineDiff::diff($base, $other) as [$op, $line]) {
			if ($op === LineDiff::EQUAL) {
				if ($current !== null) {
					$hunks[] = $current;
					$current = null;
				}

				$i++;

				continue;
			}

			$current ??= ['start' => $i, 'end' => $i, 'lines' => [], 'side' => $side];

			if ($op === LineDiff::DELETE) {
				$current['end'] = ++$i;
			} else {
				$current['lines'][] = $line;
			}
		}

		if ($current !== null) {
			$hunks[] = $current;
		}

		return $hunks;
	}

	/**
	 * Base lines start..end with one side's hunks applied.
	 *
	 * @param   string[]                                                             $base
	 * @param   array<int, array{start: int, end: int, lines: string[], side: string}>  $hunks
	 *
	 * @return  string[]
	 */
	private static function apply(array $base, int $start, int $end, array $hunks): array
	{
		$out = [];
		$pos = $start;

		foreach ($hunks as $hunk) {
			for (; $pos < $hunk['start']; $pos++) {
				$out[] = $base[$pos];
			}

			array_push($out, ...$hunk['lines']);
			$pos = max($pos, $hunk['end']);
		}

		for (; $pos < $end; $pos++) {
			$out[] = $base[$pos];
		}

		return $out;
	}

	public static function hasMarkers(string $text): bool
	{
		return (bool) preg_match('/^(<<<<<<<|=======|>>>>>>>)( |$)/m', $text);
	}
}

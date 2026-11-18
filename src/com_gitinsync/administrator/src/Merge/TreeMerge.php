<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Merge;

use Yepr\Component\GitInSync\Administrator\Content\CanonicalJson;
use Yepr\Component\GitInSync\Administrator\Content\ItemPath;
use Yepr\Component\GitInSync\Administrator\Diff\Diff3;
use Yepr\Component\GitInSync\Administrator\Git\Repository;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Three-way merge of two sets of files against their common base.
 *
 * Merge, revert and cherry-pick are all this, with a different base. Work is done per
 * item (all files of one article together), keyed by uuid, so a changed alias that renames
 * the folder doesn't look like a deletion. Inside an item, JSON files are merged per key
 * and HTML per line. One side deleting an item that the other side changed is a conflict
 * for the whole item.
 *
 * Merged blobs are written to the repository's object store; a conflict keeps our version
 * in the result and is reported for the user to resolve.
 */
final class TreeMerge
{
	public function __construct(private readonly Repository $repository)
	{
	}

	/**
	 * @param   array<string, string>  $base    Path => blob id.
	 * @param   array<string, string>  $ours
	 * @param   array<string, string>  $theirs
	 */
	public function merge(array $base, array $ours, array $theirs, string $oursLabel = 'ours', string $theirsLabel = 'theirs'): MergeResult
	{
		$baseItems   = ItemPath::group($base);
		$oursItems   = ItemPath::group($ours);
		$theirsItems = ItemPath::group($theirs);

		$result    = [];
		$conflicts = [];

		foreach (array_unique(array_merge(array_keys($baseItems), array_keys($oursItems), array_keys($theirsItems))) as $key) {
			$b = $baseItems[$key] ?? null;
			$o = $oursItems[$key] ?? null;
			$t = $theirsItems[$key] ?? null;

			if ($o == $t) {
				if ($o !== null) {
					$result[$key] = $o;
				}

				continue;
			}

			if ($b == $o) {
				if ($t !== null) {
					$result[$key] = $t;
				}

				continue;
			}

			if ($b == $t) {
				if ($o !== null) {
					$result[$key] = $o;
				}

				continue;
			}

			if ($o === null || $t === null) {
				// Deleted on one side, changed on the other.
				$conflicts[] = new Conflict($key, Conflict::DELETED, $b, $o, $t, null);

				if ($o !== null) {
					$result[$key] = $o;
				}

				continue;
			}

			[$merged, $conflict] = $this->mergeItem($key, $b, $o, $t, $oursLabel, $theirsLabel);
			$result[$key]        = $merged;

			if ($conflict !== null) {
				$conflicts[] = $conflict;
			}
		}

		return new MergeResult(ItemPath::ungroup($result), $conflicts);
	}

	/**
	 * @param   array{folder: string, files: array<string, string>}|null  $base
	 * @param   array{folder: string, files: array<string, string>}       $ours
	 * @param   array{folder: string, files: array<string, string>}       $theirs
	 *
	 * @return  array{0: array{folder: string, files: array<string, string>}, 1: ?Conflict}
	 */
	private function mergeItem(string $key, ?array $base, array $ours, array $theirs, string $oursLabel, string $theirsLabel): array
	{
		$baseFiles = $base['files'] ?? [];
		$files     = [];
		$details   = [];

		foreach (array_unique(array_merge(array_keys($baseFiles), array_keys($ours['files']), array_keys($theirs['files']))) as $file) {
			$b = $baseFiles[$file] ?? null;
			$o = $ours['files'][$file] ?? null;
			$t = $theirs['files'][$file] ?? null;

			if ($o === $t || $b === $t) {
				$id = $o;
			} elseif ($b === $o) {
				$id = $t;
			} else {
				$baseText   = $b === null ? '' : $this->repository->readBlob($b);
				$oursText   = $o === null ? '' : $this->repository->readBlob($o);
				$theirsText = $t === null ? '' : $this->repository->readBlob($t);

				if (str_ends_with($file, '.json')) {
					$merged = JsonMerge::merge(
						$baseText === '' ? [] : CanonicalJson::decode($baseText),
						$oursText === '' ? [] : CanonicalJson::decode($oursText),
						$theirsText === '' ? [] : CanonicalJson::decode($theirsText)
					);
					$text = CanonicalJson::encode($merged['result']);

					foreach ($merged['conflicts'] as $c) {
						$details[] = ['file' => $file, 'field' => $c['path'], 'base' => $c['base'], 'ours' => $c['ours'], 'theirs' => $c['theirs']];
					}
				} else {
					$merged = Diff3::merge($baseText, $oursText, $theirsText, $oursLabel, $theirsLabel);
					$text   = $merged['text'];

					if ($merged['conflicts'] > 0) {
						$details[] = ['file' => $file, 'field' => null, 'base' => $baseText, 'ours' => $oursText, 'theirs' => $theirsText];

						// The result keeps our text; the text with markers is offered for editing.
						$details[\count($details) - 1]['markers'] = $text;
						$text                                     = $oursText;
					}
				}

				$id = $this->repository->writeBlob($text);
			}

			if ($id !== null) {
				$files[$file] = $id;
			}
		}

		$folder = $ours['folder'] === ($base['folder'] ?? null) ? $theirs['folder'] : $ours['folder'];
		$item   = ['folder' => $folder, 'files' => $files];

		if ($details === []) {
			return [$item, null];
		}

		return [$item, new Conflict($key, Conflict::CONTENT, $base, $ours, $theirs, $details)];
	}
}

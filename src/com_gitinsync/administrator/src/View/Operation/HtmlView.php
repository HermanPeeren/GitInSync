<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\View\Operation;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Yepr\Component\GitInSync\Administrator\Sync\OperationStore;
use Yepr\Component\GitInSync\Administrator\View\BaseView;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One operation: its conflicts to resolve, its progress, or its outcome.
 */
final class HtmlView extends BaseView
{
	/**
	 * @var array{
	 *     id: int, kind: string, status: string, branch: string, title: string,
	 *     message: string, created: string, created_by: int, data: array<string, mixed>
	 * }|null
	 */
	public ?array $operation = null;

	/**
	 * Texts of the files in conflict, per conflict and file: base, ours, theirs, markers.
	 *
	 * @var array<int, array<string, array<string, string>>>
	 */
	public array $texts = [];

	/**
	 * @param   string|null  $tpl
	 */
	public function display($tpl = null): void
	{
		$this->operation = $this->services()->operationStore()->get(Factory::getApplication()->getInput()->getInt('id'));

		if ($this->operation !== null && $this->operation['status'] === OperationStore::CONFLICTS) {
			$repository = $this->services()->workspace()->repository();

			foreach ((array) $this->operation['data']['conflicts'] as $index => $conflict) {
				foreach (['base', 'ours', 'theirs'] as $side) {
					foreach ((array) ($conflict[$side]['files'] ?? []) as $file => $blob) {
						$read = fn (): string => $repository->readBlob((string) $blob);

						$this->texts[(int) $index][(string) $file][$side] = $this->attempt($read, '');
					}
				}

				foreach ((array) ($conflict['details'] ?? []) as $detail) {
					if (isset($detail['markers'], $detail['file'])) {
						$this->texts[(int) $index][(string) $detail['file']]['markers'] = (string) $detail['markers'];
					}
				}
			}
		}

		$this->toolbar(Text::_('COM_GITINSYNC_OPERATION'));

		if ($this->operation !== null && $this->operation['status'] === OperationStore::APPLYING) {
			$this->getDocument()->getWebAssetManager()->useScript('com_gitinsync.run');
		}

		parent::display($tpl);
	}
}

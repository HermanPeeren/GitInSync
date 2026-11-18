<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A link to the item's history in GitInSync, on the GitInSync tab of an edit form.
 */
class GitinsynchistoryField extends FormField
{
	/**
	 * @var string
	 */
	protected $type = 'Gitinsynchistory';

	protected function getInput(): string
	{
		$id   = (int) $this->form->getValue('id');
		$type = (string) ($this->element['contenttype'] ?? '');

		if ($id <= 0 || $type === '') {
			return '<p class="form-control-plaintext">' . Text::_('PLG_CONTENT_GITINSYNC_HISTORY_NEW') . '</p>';
		}

		$url = Route::_('index.php?option=com_gitinsync&view=item&type=' . rawurlencode($type) . '&id=' . $id);

		return '<a class="btn btn-secondary" id="gitinsync-item-history-link" href="' . $url . '" target="_blank" rel="noopener">'
			. Text::_('PLG_CONTENT_GITINSYNC_HISTORY_OPEN') . '</a>';
	}
}

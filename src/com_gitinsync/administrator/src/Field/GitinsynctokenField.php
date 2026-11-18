<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Field;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Field\PasswordField;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The GitHub token: never sent back to the browser.
 *
 * The field shows whether a token is set. Left empty, the stored token stays; a single "-"
 * removes it.
 */
class GitinsynctokenField extends PasswordField
{
	/**
	 * @var string
	 */
	protected $type = 'Gitinsynctoken';

	protected function getInput()
	{
		$isSet       = (string) $this->value !== '';
		$this->value = '';
		$this->hint  = Text::_($isSet ? 'COM_GITINSYNC_CONFIG_GITHUB_TOKEN_IS_SET' : 'COM_GITINSYNC_CONFIG_GITHUB_TOKEN_NOT_SET');

		return parent::getInput();
	}

	/**
	 * Form filter: keeps the stored token when the field is left empty.
	 */
	public static function keep(mixed $value): string
	{
		$value = trim((string) $value);

		if ($value === '-') {
			return '';
		}

		if ($value === '') {
			return (string) ComponentHelper::getParams('com_gitinsync')->get('github_token', '');
		}

		return $value;
	}
}

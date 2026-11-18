<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Yepr\Component\GitInSync\Administrator\View\Run\HtmlView $this */

Text::script('COM_GITINSYNC_RUN_DONE');
Text::script('COM_GITINSYNC_RUN_FAILED');
?>
<div class="gitinsync-run card card-body" id="gitinsync-run"
	data-url="<?php echo Route::_('index.php?option=com_gitinsync&task=sync.step&format=json&job=' . $this->job, false); ?>"
	data-token="<?php echo Session::getFormToken(); ?>"
	data-done-url="<?php echo Route::_('index.php?option=com_gitinsync&view=dashboard', false); ?>">
	<h2 class="h4"><?php echo Text::_('COM_GITINSYNC_JOB_' . strtoupper($this->job)); ?></h2>
	<p class="gitinsync-run-status"><?php echo Text::_('COM_GITINSYNC_RUNNING'); ?></p>
	<div class="progress mb-2"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 5%"></div></div>
	<ul class="gitinsync-run-log"></ul>
</div>

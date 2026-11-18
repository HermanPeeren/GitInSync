<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Yepr\Component\GitInSync\Administrator\View\History\HtmlView $this */
?>
<?php if ($this->error !== null) : ?>
	<div class="alert alert-danger"><?php echo $this->escape($this->error); ?></div>
<?php endif; ?>

<form action="<?php echo Route::_('index.php?option=com_gitinsync&view=history'); ?>" method="get" class="mb-3 d-flex gap-2 align-items-center">
	<input type="hidden" name="option" value="com_gitinsync">
	<input type="hidden" name="view" value="history">
	<label for="gitinsync-branch-select"><?php echo Text::_('COM_GITINSYNC_BRANCH'); ?></label>
	<select name="branch" id="gitinsync-branch-select" class="form-select w-auto" onchange="this.form.submit()">
		<?php foreach (array_keys($this->branches) as $name) : ?>
			<option value="<?php echo $this->escape((string) $name); ?>"<?php echo $name === $this->branch ? ' selected' : ''; ?>>
				<?php echo $this->escape((string) $name) . ($name === $this->siteBranch ? ' (' . Text::_('COM_GITINSYNC_SITE_BRANCH') . ')' : ''); ?>
			</option>
		<?php endforeach; ?>
	</select>
</form>

<?php if ($this->commits === []) : ?>
	<p><?php echo Text::_('COM_GITINSYNC_NO_COMMITS'); ?></p>
<?php else : ?>
	<table class="table table-striped" id="gitinsync-history">
		<thead>
			<tr>
				<th scope="col"><?php echo Text::_('COM_GITINSYNC_COMMIT'); ?></th>
				<th scope="col"><?php echo Text::_('COM_GITINSYNC_MESSAGE'); ?></th>
				<th scope="col"><?php echo Text::_('COM_GITINSYNC_AUTHOR'); ?></th>
				<th scope="col"><?php echo Text::_('JDATE'); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($this->commits as $id => $commit) : ?>
			<tr>
				<td><a href="<?php echo Route::_('index.php?option=com_gitinsync&view=commit&id=' . $id); ?>"><code><?php echo substr($id, 0, 7); ?></code></a></td>
				<td><?php echo $this->escape($commit->subject()); ?><?php echo \count($commit->parents) > 1 ? ' <span class="badge bg-info">' . Text::_('COM_GITINSYNC_MERGE') . '</span>' : ''; ?></td>
				<td><?php echo $this->escape($commit->author->name); ?></td>
				<td class="text-nowrap"><?php echo HTMLHelper::_('date', '@' . $commit->author->timestamp, Text::_('DATE_FORMAT_LC5')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<nav class="d-flex gap-2">
		<?php if ($this->start > 0) : ?>
			<a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_gitinsync&view=history&branch=' . rawurlencode($this->branch) . '&start=' . max(0, $this->start - $this::PAGE)); ?>"><?php echo Text::_('JPREV'); ?></a>
		<?php endif; ?>
		<?php if (\count($this->commits) === $this::PAGE) : ?>
			<a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_gitinsync&view=history&branch=' . rawurlencode($this->branch) . '&start=' . ($this->start + $this::PAGE)); ?>"><?php echo Text::_('JNEXT'); ?></a>
		<?php endif; ?>
	</nav>
<?php endif; ?>

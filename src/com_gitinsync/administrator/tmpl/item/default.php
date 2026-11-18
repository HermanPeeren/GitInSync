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

/** @var \Yepr\Component\GitInSync\Administrator\View\Item\HtmlView $this */

$first = true;
?>
<?php if ($this->error !== null) : ?>
	<div class="alert alert-danger"><?php echo $this->escape($this->error); ?></div>
<?php endif; ?>

<?php if ($this->type === null) : ?>
	<p><?php echo Text::_('COM_GITINSYNC_NO_SUCH_ITEM'); ?></p>
<?php else : ?>
	<h2 class="h4"><code><?php echo $this->escape($this->key); ?></code></h2>
	<?php if ($this->localId !== null) : ?>
		<p><a href="<?php echo Route::_($this->type->editLink($this->localId)); ?>"><?php echo Text::_('COM_GITINSYNC_EDIT_ITEM'); ?></a></p>
	<?php else : ?>
		<p class="text-muted"><?php echo Text::_('COM_GITINSYNC_NOT_ON_SITE'); ?></p>
	<?php endif; ?>

	<?php if ($this->excluded) : ?>
		<div class="alert alert-info"><?php echo Text::_('COM_GITINSYNC_ITEM_EXCLUDED'); ?></div>
	<?php endif; ?>

	<?php if ($this->pending !== []) : ?>
		<div class="card mb-3" id="gitinsync-pending">
			<div class="card-header"><?php echo Text::_('COM_GITINSYNC_UNCOMMITTED'); ?></div>
			<div class="card-body">
				<?php foreach ($this->pending as $file => $diff) : ?>
					<h3 class="h6"><?php echo $this->escape((string) $file); ?></h3>
					<pre class="border p-2"><?php
					foreach (explode("\n", $diff) as $line) {
						$class = str_starts_with($line, '+') ? 'text-success' : (str_starts_with($line, '-') ? 'text-danger' : 'text-muted');
						echo '<span class="' . $class . '">' . $this->escape($line) . "</span>\n";
					}
					?></pre>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<table class="table" id="gitinsync-item-history">
		<thead>
			<tr>
				<th scope="col"><?php echo Text::_('COM_GITINSYNC_COMMIT'); ?></th>
				<th scope="col"><?php echo Text::_('COM_GITINSYNC_MESSAGE'); ?></th>
				<th scope="col"><?php echo Text::_('COM_GITINSYNC_AUTHOR'); ?></th>
				<th scope="col"><?php echo Text::_('JDATE'); ?></th>
				<th scope="col"></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($this->commits as $id => $commit) : ?>
			<tr>
				<td><a href="<?php echo Route::_('index.php?option=com_gitinsync&view=commit&id=' . $id); ?>"><code><?php echo substr($id, 0, 7); ?></code></a></td>
				<td><?php echo $this->escape($commit->subject()); ?></td>
				<td><?php echo $this->escape($commit->author->name); ?></td>
				<td class="text-nowrap"><?php echo HTMLHelper::_('date', '@' . $commit->author->timestamp, Text::_('DATE_FORMAT_LC5')); ?></td>
				<td>
					<?php if ($first) : ?>
						<span class="badge bg-success"><?php echo Text::_('COM_GITINSYNC_CURRENT'); ?></span>
					<?php elseif ($this->canApply) : ?>
						<form action="<?php echo Route::_('index.php?option=com_gitinsync&task=operation.restore'); ?>" method="post" class="m-0">
							<input type="hidden" name="key" value="<?php echo $this->escape($this->key); ?>">
							<input type="hidden" name="commit" value="<?php echo $this->escape((string) $id); ?>">
							<button type="submit" class="btn btn-sm btn-outline-primary gitinsync-restore"><?php echo Text::_('COM_GITINSYNC_RESTORE'); ?></button>
							<?php echo HTMLHelper::_('form.token'); ?>
						</form>
					<?php endif; ?>
					<?php $first = false; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

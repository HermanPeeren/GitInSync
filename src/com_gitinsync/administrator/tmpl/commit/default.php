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

/** @var \Yepr\Component\GitInSync\Administrator\View\Commit\HtmlView $this */

$commit = $this->commit;
?>
<?php if ($this->error !== null) : ?>
	<div class="alert alert-danger"><?php echo $this->escape($this->error); ?></div>
<?php endif; ?>

<?php if ($commit === null) : ?>
	<p><?php echo Text::_('COM_GITINSYNC_NO_SUCH_COMMIT'); ?></p>
<?php else : ?>
	<div class="card mb-3">
		<div class="card-body">
			<h2 class="h4" id="gitinsync-commit-subject"><?php echo $this->escape($commit->subject()); ?></h2>
			<?php $body = trim(substr($commit->message, \strlen(strtok($commit->message, "\n") ?: ''))); ?>
			<?php if ($body !== '') : ?>
				<pre class="mb-2"><?php echo $this->escape($body); ?></pre>
			<?php endif; ?>
			<p class="mb-1"><code><?php echo $this->escape($this->id); ?></code></p>
			<p class="mb-1">
				<?php echo Text::sprintf('COM_GITINSYNC_AUTHORED_BY', $this->escape($commit->author->name), HTMLHelper::_('date', '@' . $commit->author->timestamp, Text::_('DATE_FORMAT_LC2'))); ?>
			</p>
			<?php foreach ($commit->parents as $parent) : ?>
				<p class="mb-1"><?php echo Text::_('COM_GITINSYNC_PARENT'); ?>: <a href="<?php echo Route::_('index.php?option=com_gitinsync&view=commit&id=' . $parent); ?>"><code><?php echo substr($parent, 0, 7); ?></code></a></p>
			<?php endforeach; ?>

			<?php if ($this->canApply) : ?>
				<form action="<?php echo Route::_('index.php?option=com_gitinsync'); ?>" method="post" class="mt-3 d-flex gap-2">
					<input type="hidden" name="commit" value="<?php echo $this->escape($this->id); ?>">
					<?php if ($this->onSiteBranch) : ?>
						<button type="submit" name="task" value="operation.revert" class="btn btn-warning" id="gitinsync-revert"
							onclick="return confirm(Joomla.Text._('COM_GITINSYNC_CONFIRM_REVERT'));">
							<span class="icon-undo" aria-hidden="true"></span> <?php echo Text::_('COM_GITINSYNC_REVERT'); ?>
						</button>
					<?php else : ?>
						<button type="submit" name="task" value="operation.cherrypick" class="btn btn-primary" id="gitinsync-cherrypick">
							<?php echo Text::_('COM_GITINSYNC_CHERRYPICK'); ?>
						</button>
					<?php endif; ?>
					<?php echo HTMLHelper::_('form.token'); ?>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<?php foreach ($this->changes as $key => $files) : ?>
		<div class="card mb-3">
			<div class="card-header d-flex justify-content-between align-items-center">
				<a href="<?php echo Route::_('index.php?option=com_gitinsync&view=item&key=' . rawurlencode((string) $key)); ?>"><code><?php echo $this->escape((string) $key); ?></code></a>
				<?php if ($this->canApply && str_contains((string) $key, '/')) : ?>
					<form action="<?php echo Route::_('index.php?option=com_gitinsync&task=operation.restore'); ?>" method="post" class="m-0">
						<input type="hidden" name="key" value="<?php echo $this->escape((string) $key); ?>">
						<input type="hidden" name="commit" value="<?php echo $this->escape($this->id); ?>">
						<button type="submit" class="btn btn-sm btn-outline-primary"><?php echo Text::_('COM_GITINSYNC_RESTORE_THIS_VERSION'); ?></button>
						<?php echo HTMLHelper::_('form.token'); ?>
					</form>
				<?php endif; ?>
			</div>
			<div class="card-body">
				<?php foreach ($files as $file => $change) : ?>
					<h3 class="h6"><?php echo $this->escape((string) $file); ?> <span class="badge bg-secondary"><?php echo Text::_('COM_GITINSYNC_FILE_' . strtoupper($change['status'])); ?></span></h3>
					<pre class="gitinsync-diff border p-2"><?php
					foreach (explode("\n", $change['diff']) as $line) {
						$class = str_starts_with($line, '+') ? 'text-success' : (str_starts_with($line, '-') ? 'text-danger' : 'text-muted');
						echo '<span class="' . $class . '">' . $this->escape($line) . "</span>\n";
					}
					?></pre>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endforeach; ?>
<?php endif; ?>
<?php Text::script('COM_GITINSYNC_CONFIRM_REVERT'); ?>

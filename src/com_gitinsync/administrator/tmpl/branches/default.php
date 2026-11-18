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

/** @var \Yepr\Component\GitInSync\Administrator\View\Branches\HtmlView $this */
?>
<?php if ($this->error !== null) : ?>
	<div class="alert alert-danger"><?php echo $this->escape($this->error); ?></div>
<?php endif; ?>

<p><?php echo Text::_('COM_GITINSYNC_BRANCHES_HELP'); ?></p>

<table class="table" id="gitinsync-branches">
	<thead>
		<tr>
			<th scope="col"><?php echo Text::_('COM_GITINSYNC_BRANCH'); ?></th>
			<th scope="col"><?php echo Text::_('COM_GITINSYNC_LAST_COMMIT'); ?></th>
			<th scope="col"><?php echo Text::_('COM_GITINSYNC_ACTIONS'); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ($this->branches as $name => $branch) : ?>
		<?php $name = (string) $name; ?>
		<tr data-branch="<?php echo $this->escape($name); ?>">
			<td>
				<code><?php echo $this->escape($name); ?></code>
				<?php if ($name === $this->siteBranch) : ?>
					<span class="badge bg-success"><?php echo Text::_('COM_GITINSYNC_SITE_BRANCH'); ?></span>
				<?php elseif ($branch['merged']) : ?>
					<span class="badge bg-secondary"><?php echo Text::_('COM_GITINSYNC_MERGED'); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<a href="<?php echo Route::_('index.php?option=com_gitinsync&view=history&branch=' . rawurlencode($name)); ?>"><code><?php echo substr($branch['id'], 0, 7); ?></code></a>
				<?php echo $branch['commit'] === null ? '' : $this->escape($branch['commit']->subject()); ?>
			</td>
			<td>
				<?php if ($this->canApply && $name !== $this->siteBranch) : ?>
					<form action="<?php echo Route::_('index.php?option=com_gitinsync'); ?>" method="post" class="d-flex gap-2 m-0">
						<input type="hidden" name="branch" value="<?php echo $this->escape($name); ?>">
						<?php if (!$branch['merged']) : ?>
							<button type="submit" name="task" value="operation.merge" class="btn btn-sm btn-primary gitinsync-merge">
								<?php echo Text::sprintf('COM_GITINSYNC_MERGE_INTO', $this->escape($this->siteBranch)); ?>
							</button>
						<?php endif; ?>
						<button type="submit" name="task" value="operation.checkout" class="btn btn-sm btn-warning gitinsync-checkout"
							onclick="return confirm(Joomla.Text._('COM_GITINSYNC_CONFIRM_CHECKOUT'));">
							<?php echo Text::_('COM_GITINSYNC_SWITCH'); ?>
						</button>
						<button type="submit" name="task" value="operation.deletebranch" class="btn btn-sm btn-outline-danger gitinsync-delete"
							onclick="return confirm(Joomla.Text._('COM_GITINSYNC_CONFIRM_DELETE_BRANCH'));">
							<?php echo Text::_('JACTION_DELETE'); ?>
						</button>
						<?php echo HTMLHelper::_('form.token'); ?>
					</form>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php if ($this->canApply) : ?>
	<form action="<?php echo Route::_('index.php?option=com_gitinsync&task=operation.createbranch'); ?>" method="post" class="card card-body d-flex flex-row gap-2 align-items-end" id="gitinsync-new-branch">
		<div>
			<label for="gitinsync-branch-name" class="form-label"><?php echo Text::_('COM_GITINSYNC_NEW_BRANCH'); ?></label>
			<input type="text" name="name" id="gitinsync-branch-name" class="form-control" pattern="[A-Za-z0-9._/-]+" required>
		</div>
		<button type="submit" class="btn btn-success"><?php echo Text::sprintf('COM_GITINSYNC_CREATE_FROM', $this->escape($this->siteBranch)); ?></button>
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
<?php endif; ?>
<?php Text::script('COM_GITINSYNC_CONFIRM_CHECKOUT'); ?>
<?php Text::script('COM_GITINSYNC_CONFIRM_DELETE_BRANCH'); ?>

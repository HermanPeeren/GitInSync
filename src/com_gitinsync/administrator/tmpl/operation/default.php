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
use Joomla\CMS\Session\Session;
use Yepr\Component\GitInSync\Administrator\Sync\OperationStore;

/** @var \Yepr\Component\GitInSync\Administrator\View\Operation\HtmlView $this */

$operation = $this->operation;
?>
<?php if ($operation === null) : ?>
	<p><?php echo Text::_('COM_GITINSYNC_NO_SUCH_OPERATION'); ?></p>
<?php else : ?>
	<h2 class="h4" id="gitinsync-operation-title"><?php echo $this->escape($operation['title']); ?></h2>
	<p>
		<span class="badge bg-secondary" id="gitinsync-operation-status" data-status="<?php echo $this->escape($operation['status']); ?>">
			<?php echo Text::_('COM_GITINSYNC_STATUS_' . strtoupper($operation['status'])); ?>
		</span>
	</p>

	<?php if ($operation['message'] !== '') : ?>
		<div class="alert <?php echo $operation['status'] === OperationStore::DONE ? 'alert-success' : 'alert-warning'; ?>" id="gitinsync-operation-message">
			<?php echo $this->escape($operation['message']); ?>
		</div>
	<?php endif; ?>

	<?php if ($operation['status'] === OperationStore::APPLYING) : ?>
		<?php Text::script('COM_GITINSYNC_RUN_DONE'); ?>
		<?php Text::script('COM_GITINSYNC_RUN_FAILED'); ?>
		<div class="gitinsync-run card card-body"
			data-url="<?php echo Route::_('index.php?option=com_gitinsync&task=operation.step&format=json&id=' . $operation['id'], false); ?>"
			data-token="<?php echo Session::getFormToken(); ?>"
			data-done-url="<?php echo Route::_('index.php?option=com_gitinsync&view=operation&id=' . $operation['id'], false); ?>">
			<p class="gitinsync-run-status"><?php echo Text::_('COM_GITINSYNC_APPLYING'); ?></p>
			<div class="progress mb-2"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 5%"></div></div>
			<ul class="gitinsync-run-log"></ul>
		</div>
	<?php endif; ?>

	<?php if ($operation['status'] === OperationStore::CONFLICTS) : ?>
		<p><?php echo Text::_('COM_GITINSYNC_CONFLICTS_HELP'); ?></p>
		<form action="<?php echo Route::_('index.php?option=com_gitinsync&task=operation.resolve&id=' . $operation['id']); ?>" method="post" id="gitinsync-conflicts">
			<?php foreach ((array) $operation['data']['conflicts'] as $index => $conflict) : ?>
				<?php $index = (int) $index; ?>
				<fieldset class="card mb-3 gitinsync-conflict">
					<legend class="card-header h5"><code><?php echo $this->escape((string) $conflict['key']); ?></code></legend>
					<div class="card-body">
						<?php if ($conflict['kind'] === 'deleted') : ?>
							<p><?php echo Text::_($conflict['ours'] === null ? 'COM_GITINSYNC_CONFLICT_DELETED_OURS' : 'COM_GITINSYNC_CONFLICT_DELETED_THEIRS'); ?></p>
						<?php else : ?>
							<ul>
								<?php foreach ((array) $conflict['details'] as $detail) : ?>
									<li>
										<?php echo $this->escape((string) $detail['file']); ?><?php echo $detail['field'] === null ? '' : ': <strong>' . $this->escape((string) $detail['field']) . '</strong>'; ?>
										<?php if ($detail['field'] !== null) : ?>
											<?php echo Text::sprintf(
												'COM_GITINSYNC_CONFLICT_VALUES',
												$this->escape((string) json_encode($detail['ours'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
												$this->escape((string) json_encode($detail['theirs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
											); ?>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<div class="d-flex gap-3 mb-2">
							<?php foreach (['ours', 'theirs'] as $side) : ?>
								<?php if ($conflict[$side] !== null || $conflict['kind'] === 'content') : ?>
									<label><input type="radio" name="conflict[<?php echo $index; ?>][choice]" value="<?php echo $side; ?>" required>
										<?php echo Text::_('COM_GITINSYNC_KEEP_' . strtoupper($side)); ?></label>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ($conflict['kind'] === 'deleted') : ?>
								<label><input type="radio" name="conflict[<?php echo $index; ?>][choice]" value="delete" required>
									<?php echo Text::_('COM_GITINSYNC_KEEP_DELETED'); ?></label>
							<?php else : ?>
								<label><input type="radio" name="conflict[<?php echo $index; ?>][choice]" value="edit" required>
									<?php echo Text::_('COM_GITINSYNC_KEEP_EDITED'); ?></label>
							<?php endif; ?>
						</div>

						<?php if ($conflict['kind'] === 'content') : ?>
							<?php foreach ($this->texts[$index] ?? [] as $file => $texts) : ?>
								<?php if (($texts['ours'] ?? null) === ($texts['theirs'] ?? null)) : ?>
									<?php continue; ?>
								<?php endif; ?>
								<details class="mb-2">
									<summary><?php echo Text::sprintf('COM_GITINSYNC_EDIT_FILE', $this->escape((string) $file)); ?></summary>
									<textarea class="form-control font-monospace" rows="12"
										name="conflictfiles[<?php echo $index; ?>][<?php echo $this->escape((string) $file); ?>]"><?php
										echo $this->escape($texts['markers'] ?? $texts['ours'] ?? $texts['theirs'] ?? '');
									?></textarea>
								</details>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</fieldset>
			<?php endforeach; ?>

			<?php if ($this->canApply) : ?>
				<button type="submit" class="btn btn-primary" id="gitinsync-resolve"><?php echo Text::_('COM_GITINSYNC_RESOLVE'); ?></button>
			<?php endif; ?>
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
	<?php endif; ?>

	<?php if ($this->canApply && \in_array($operation['status'], [OperationStore::CONFLICTS, OperationStore::APPLYING], true)) : ?>
		<form action="<?php echo Route::_('index.php?option=com_gitinsync&task=operation.abort&id=' . $operation['id']); ?>" method="post" class="mt-3">
			<button type="submit" class="btn btn-outline-danger" onclick="return confirm(Joomla.Text._('COM_GITINSYNC_CONFIRM_ABORT'));"><?php echo Text::_('COM_GITINSYNC_ABORT'); ?></button>
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
		<?php Text::script('COM_GITINSYNC_CONFIRM_ABORT'); ?>
	<?php endif; ?>
<?php endif; ?>

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
use Yepr\Component\GitInSync\Administrator\Sync\Settings;

/** @var \Yepr\Component\GitInSync\Administrator\View\Dashboard\HtmlView $this */

$settings = $this->settings;
$mode     = $settings->mode();
?>
<div class="gitinsync-dashboard">
	<?php if ($this->error !== null) : ?>
		<div class="alert alert-danger"><?php echo $this->escape($this->error); ?></div>
	<?php endif; ?>

	<?php if ($this->openOperation !== null) : ?>
		<div class="alert alert-warning">
			<?php echo Text::_('COM_GITINSYNC_OPEN_OPERATION'); ?>
			<a href="<?php echo Route::_('index.php?option=com_gitinsync&view=operation&id=' . $this->openOperation); ?>"><?php echo Text::_('COM_GITINSYNC_OPEN_OPERATION_LINK'); ?></a>
		</div>
	<?php endif; ?>

	<?php if ($this->githubHead !== null && $this->head !== null && $this->githubHead !== $this->head) : ?>
		<div class="alert alert-info"><?php echo Text::_('COM_GITINSYNC_GITHUB_MOVED'); ?></div>
	<?php endif; ?>

	<div class="row">
		<div class="col-lg-5">
			<div class="card mb-3">
				<div class="card-header"><h2 class="h4 mb-0"><?php echo Text::_('COM_GITINSYNC_STATUS'); ?></h2></div>
				<div class="card-body">
					<dl class="row mb-0">
						<dt class="col-sm-5"><?php echo Text::_('COM_GITINSYNC_MODE'); ?></dt>
						<dd class="col-sm-7" id="gitinsync-mode"><?php echo Text::_('COM_GITINSYNC_MODE_' . strtoupper($mode)); ?></dd>

						<?php if ($settings->usesLocal()) : ?>
							<dt class="col-sm-5"><?php echo Text::_('COM_GITINSYNC_LOCAL_REPOSITORY'); ?></dt>
							<dd class="col-sm-7"><code><?php echo $this->escape($settings->repositoryPath()); ?></code></dd>
						<?php endif; ?>

						<?php if ($settings->usesGitHub()) : ?>
							<dt class="col-sm-5"><?php echo Text::_('COM_GITINSYNC_GITHUB_REPOSITORY'); ?></dt>
							<dd class="col-sm-7">
								<?php if ($settings->hasGitHubCredentials()) : ?>
									<?php echo $this->escape($settings->githubOwner() . '/' . $settings->githubRepository()); ?>
									<?php if ($this->private !== null) : ?>
										<span class="badge <?php echo $this->private ? 'bg-success' : 'bg-warning text-dark'; ?>">
											<?php echo Text::_($this->private ? 'COM_GITINSYNC_PRIVATE' : 'COM_GITINSYNC_PUBLIC'); ?>
										</span>
									<?php endif; ?>
								<?php else : ?>
									<span class="text-danger"><?php echo Text::_('COM_GITINSYNC_GITHUB_NOT_SET'); ?></span>
								<?php endif; ?>
							</dd>
						<?php endif; ?>

						<dt class="col-sm-5"><?php echo Text::_('COM_GITINSYNC_BRANCH'); ?></dt>
						<dd class="col-sm-7" id="gitinsync-branch"><code><?php echo $this->escape($this->branch); ?></code></dd>

						<dt class="col-sm-5"><?php echo Text::_('COM_GITINSYNC_LAST_COMMIT'); ?></dt>
						<dd class="col-sm-7"><?php echo $this->head === null ? Text::_('COM_GITINSYNC_NO_COMMITS') : '<code>' . substr($this->head, 0, 7) . '</code>'; ?></dd>

						<dt class="col-sm-5"><?php echo Text::_('COM_GITINSYNC_WAITING'); ?></dt>
						<dd class="col-sm-7" id="gitinsync-dirty"><?php echo (int) $this->dirty; ?></dd>
					</dl>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header"><h2 class="h4 mb-0"><?php echo Text::_('COM_GITINSYNC_ACTIONS'); ?></h2></div>
				<div class="card-body d-grid gap-2">
					<a class="btn btn-primary" id="gitinsync-commit" href="<?php echo Route::_('index.php?option=com_gitinsync&view=run&job=commit'); ?>">
						<span class="icon-check" aria-hidden="true"></span> <?php echo Text::_('COM_GITINSYNC_JOB_COMMIT'); ?>
					</a>
					<a class="btn btn-secondary" id="gitinsync-export" href="<?php echo Route::_('index.php?option=com_gitinsync&view=run&job=export'); ?>">
						<span class="icon-refresh" aria-hidden="true"></span> <?php echo Text::_('COM_GITINSYNC_JOB_EXPORT'); ?>
					</a>
					<?php if ($mode === Settings::MODE_LOCAL_GITHUB) : ?>
						<a class="btn btn-secondary" id="gitinsync-push" href="<?php echo Route::_('index.php?option=com_gitinsync&view=run&job=push'); ?>">
							<span class="icon-upload" aria-hidden="true"></span> <?php echo Text::_('COM_GITINSYNC_JOB_PUSH'); ?>
						</a>
					<?php endif; ?>
					<?php if ($mode !== Settings::MODE_LOCAL && $this->canApply) : ?>
						<a class="btn btn-secondary" id="gitinsync-pull" href="<?php echo Route::_('index.php?option=com_gitinsync&view=run&job=pull'); ?>">
							<span class="icon-download" aria-hidden="true"></span> <?php echo Text::_('COM_GITINSYNC_JOB_PULL'); ?>
						</a>
					<?php endif; ?>
					<?php if ($mode !== Settings::MODE_LOCAL) : ?>
						<form action="<?php echo Route::_('index.php?option=com_gitinsync&task=sync.testconnection'); ?>" method="post" class="d-grid">
							<button type="submit" class="btn btn-outline-secondary"><?php echo Text::_('COM_GITINSYNC_TEST_CONNECTION'); ?></button>
							<?php echo HTMLHelper::_('form.token'); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="col-lg-7">
			<div class="card mb-3">
				<div class="card-header"><h2 class="h4 mb-0"><?php echo Text::_('COM_GITINSYNC_RECENT_COMMITS'); ?></h2></div>
				<?php if ($this->commits === []) : ?>
					<div class="card-body"><?php echo Text::_('COM_GITINSYNC_NO_COMMITS_HELP'); ?></div>
				<?php else : ?>
					<table class="table table-sm mb-0" id="gitinsync-commits">
						<tbody>
						<?php foreach ($this->commits as $id => $commit) : ?>
							<tr>
								<td><a href="<?php echo Route::_('index.php?option=com_gitinsync&view=commit&id=' . $id); ?>"><code><?php echo substr($id, 0, 7); ?></code></a></td>
								<td><?php echo $this->escape($commit->subject()); ?></td>
								<td class="text-nowrap"><?php echo $this->escape($commit->author->name); ?></td>
								<td class="text-nowrap"><?php echo HTMLHelper::_('date', '@' . $commit->author->timestamp, Text::_('DATE_FORMAT_LC5')); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ($this->operations !== []) : ?>
				<div class="card mb-3">
					<div class="card-header"><h2 class="h4 mb-0"><?php echo Text::_('COM_GITINSYNC_RECENT_OPERATIONS'); ?></h2></div>
					<table class="table table-sm mb-0">
						<tbody>
						<?php foreach ($this->operations as $operation) : ?>
							<tr>
								<td><a href="<?php echo Route::_('index.php?option=com_gitinsync&view=operation&id=' . $operation['id']); ?>"><?php echo $this->escape($operation['title']); ?></a></td>
								<td><?php echo Text::_('COM_GITINSYNC_STATUS_' . strtoupper($operation['status'])); ?></td>
								<td class="text-nowrap"><?php echo HTMLHelper::_('date', $operation['created'], Text::_('DATE_FORMAT_LC5')); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

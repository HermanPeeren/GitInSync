<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\GitInSync\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Yepr\Component\GitInSync\Administrator\Extension\GitInSyncComponent;
use Yepr\Component\GitInSync\Administrator\Job\Budget;
use Yepr\Component\GitInSync\Administrator\Service\Services;
use Yepr\Component\GitInSync\Administrator\Sync\OperationStore;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Two task types for the Task Scheduler.
 *
 * "GitInSync: commit changes" commits what was saved (and, optionally, what a scan finds)
 * and pushes it to GitHub. "GitInSync: pull from GitHub" brings in what was committed on
 * GitHub and applies it to the site. Both work in portions: when the time budget runs out
 * with work left they return WILL_RESUME, so the scheduler runs them again on the next
 * tick and they go on where they stopped.
 */
final class GitInSync extends CMSPlugin implements SubscriberInterface
{
	use TaskPluginTrait;

	/**
	 * @var array<string, array<string, string>>
	 */
	protected const TASKS_MAP = [
		'gitinsync.commit' => [
			'langConstPrefix' => 'PLG_TASK_GITINSYNC_COMMIT',
			'form'            => 'commit',
			'method'          => 'commit',
		],
		'gitinsync.pull' => [
			'langConstPrefix' => 'PLG_TASK_GITINSYNC_PULL',
			'form'            => 'pull',
			'method'          => 'pull',
		],
	];

	/**
	 * @var boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * @return  array<string, string>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onTaskOptionsList'    => 'advertiseRoutines',
			'onExecuteTask'        => 'standardRoutineHandler',
			'onContentPrepareForm' => 'enhanceTaskItemForm',
		];
	}

	/**
	 * Scan (optional), commit, push (in "local + GitHub" mode, optional).
	 *
	 * The phase is kept in GitInSync's state, so a resumed run goes on with the phase it
	 * was in.
	 *
	 * @return  integer  A TaskStatus exit code.
	 */
	protected function commit(ExecuteTaskEvent $event): int
	{
		$params   = $this->params($event);
		$budget   = new Budget($this->budget($params));
		$scan     = (bool) ($params->scan ?? false);
		$push     = (bool) ($params->push ?? true);
		$services = $this->services();

		if ($services === null) {
			return TaskStatus::KNOCKOUT;
		}

		$state = $services->state();
		$key   = 'task.' . $event->getTaskId() . '.phase';
		$phase = $state->get($key) ?? ($scan ? 'scan' : 'commit');

		try {
			if ($phase === 'scan') {
				$result = $services->scanner()->scan($budget);
				$this->logTask($result['message']);

				if (!$result['done']) {
					$state->set($key, 'scan');

					return TaskStatus::WILL_RESUME;
				}

				$phase = 'commit';
			}

			if ($phase === 'commit') {
				$result = $services->committer()->commitDirty($budget);
				$this->logTask($result['message']);

				if (!$result['done']) {
					$state->set($key, 'commit');

					return TaskStatus::WILL_RESUME;
				}

				$phase = 'push';
			}

			$transfer = $services->workspace()->transfer();

			if ($push && $transfer !== null) {
				$result = $transfer->push($services->workspace()->branch(), $budget);
				$this->logTask($result['message']);

				if (!$result['done']) {
					$state->set($key, 'push');

					return TaskStatus::WILL_RESUME;
				}
			}
		} catch (\RuntimeException $e) {
			$state->delete($key);
			$this->logTask(\sprintf(Text::_('PLG_TASK_GITINSYNC_LOG_FAILED'), $e->getMessage()), 'error');

			return TaskStatus::KNOCKOUT;
		}

		$state->delete($key);
		$this->logTask(Text::_('PLG_TASK_GITINSYNC_LOG_ALL_DONE'));

		return TaskStatus::OK;
	}

	/**
	 * Fetches the site's branch from GitHub, merges it and applies the result to the
	 * site, as the chosen user. Conflicts stop the task: they are resolved in GitInSync.
	 *
	 * @return  integer  A TaskStatus exit code.
	 */
	protected function pull(ExecuteTaskEvent $event): int
	{
		$params   = $this->params($event);
		$budget   = new Budget($this->budget($params));
		$services = $this->services();

		if ($services === null) {
			return TaskStatus::KNOCKOUT;
		}

		$user = $this->user((int) ($params->user ?? 0));

		if ($user === null) {
			$this->logTask(Text::_('PLG_TASK_GITINSYNC_LOG_NO_USER'), 'error');

			return TaskStatus::KNOCKOUT;
		}

		try {
			$store = $services->operationStore();
			$open  = $store->open();

			if ($open === null) {
				$result = $services->operations()->pull($user, $budget);
				$this->logTask($result['message']);

				if (!$result['done']) {
					return TaskStatus::WILL_RESUME;
				}

				if ($result['operation'] === 0) {
					$this->logTask(Text::_('PLG_TASK_GITINSYNC_LOG_ALL_DONE'));

					return TaskStatus::OK;
				}

				$open = $result['operation'];
			}

			$operation = $store->get($open);

			if ($operation !== null && $operation['status'] === OperationStore::CONFLICTS) {
				$this->logTask(\sprintf(Text::_('PLG_TASK_GITINSYNC_LOG_CONFLICTS'), $open), 'warning');

				return TaskStatus::OK;
			}

			$step = $services->operations()->step($open, $budget, $user);
			$this->logTask(
				$step['message'] !== ''
					? $step['message']
					: \sprintf(Text::_('PLG_TASK_GITINSYNC_LOG_APPLIED'), $step['applied'], $step['pending'])
			);

			if (!$step['done']) {
				return TaskStatus::WILL_RESUME;
			}
		} catch (\RuntimeException $e) {
			$this->logTask(\sprintf(Text::_('PLG_TASK_GITINSYNC_LOG_FAILED'), $e->getMessage()), 'error');

			return TaskStatus::KNOCKOUT;
		}

		$this->logTask(Text::_('PLG_TASK_GITINSYNC_LOG_ALL_DONE'));

		return TaskStatus::OK;
	}

	private function params(ExecuteTaskEvent $event): object
	{
		$params = $event->getArgument('params');

		return \is_object($params) ? $params : (object) [];
	}

	private function budget(object $params): int
	{
		return max(3, min(120, (int) ($params->time_budget ?? 20)));
	}

	private function user(int $id): ?User
	{
		if ($id <= 0) {
			return null;
		}

		$user = $this->userFactory()->loadUserById($id);

		return $user->id > 0 && $user->authorise('gitinsync.apply', 'com_gitinsync') ? $user : null;
	}

	private function userFactory(): UserFactoryInterface
	{
		return Factory::getContainer()->get(UserFactoryInterface::class);
	}

	private function services(): ?Services
	{
		$component = $this->getApplication()?->bootComponent('com_gitinsync');

		if (!$component instanceof GitInSyncComponent) {
			$this->logTask(Text::_('PLG_TASK_GITINSYNC_LOG_NOT_INSTALLED'), 'error');

			return null;
		}

		return $component->services();
	}
}

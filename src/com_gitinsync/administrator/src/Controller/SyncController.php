<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Yepr\Component\GitInSync\Administrator\Extension\GitInSyncComponent;
use Yepr\Component\GitInSync\Administrator\Job\Budget;
use Yepr\Component\GitInSync\Administrator\Service\Services;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The long jobs, a portion per request: the run screen calls step() until it is done.
 * That works without a command line and stays within the server's time limit.
 */
final class SyncController extends BaseController
{
	/**
	 * One portion of a job, as JSON: {done, message, operation}.
	 */
	public function step(): void
	{
		try {
			if (!Session::checkToken()) {
				throw new \RuntimeException(Text::_('JINVALID_TOKEN'));
			}

			$user = $this->user('core.manage');
			$job  = $this->input->getCmd('job');

			$services = $this->services();
			$budget   = new Budget(min(20, $services->settings()->timeBudget()));
			$result   = match ($job) {
				'commit' => $this->commit($services, $budget),
				'export' => $this->export($services, $budget),
				'push'   => $this->push($services, $budget),
				'pull'   => $this->pull($services, $budget, $this->user('gitinsync.apply')),
				default  => throw new \RuntimeException('Unknown job.'),
			};

			echo new JsonResponse($result);
		} catch (\Throwable $e) {
			echo new JsonResponse(['done' => true, 'error' => true, 'message' => $e->getMessage()], $e->getMessage(), true);
		}

		$this->app->close();
	}

	/**
	 * Checks the connection to GitHub, and refreshes what is known of the repository.
	 */
	public function testconnection(): void
	{
		$this->checkToken();

		// It uses the stored token and may write a first commit to the repository.
		$this->user('core.options');

		try {
			$workspace = $this->services()->workspace();
			$client    = $workspace->github()->client();
			$repo      = $client->repository();

			if ($repo['empty']) {
				$client->initialise($workspace->branch());
				$repo = $client->repository();
			}

			$workspace->isGitHubPrivate(true);

			$this->setMessage(Text::sprintf(
				$repo['private'] ? 'COM_GITINSYNC_CONNECTION_OK_PRIVATE' : 'COM_GITINSYNC_CONNECTION_OK_PUBLIC',
				$repo['html_url']
			));
		} catch (\Throwable $e) {
			$this->setMessage(Text::sprintf('COM_GITINSYNC_CONNECTION_FAILED', $e->getMessage()), 'error');
		}

		$this->setRedirect('index.php?option=com_gitinsync&view=dashboard');
	}

	/**
	 * @return  array{done: bool, message: string}
	 */
	private function commit(Services $services, Budget $budget): array
	{
		$result = $services->committer()->commitDirty($budget);

		return ['done' => $result['done'], 'message' => $result['message']];
	}

	/**
	 * The scan first, then committing what it found.
	 *
	 * @return  array{done: bool, message: string}
	 */
	private function export(Services $services, Budget $budget): array
	{
		if ($services->state()->get('export.phase') !== 'commit') {
			$scan = $services->scanner()->scan($budget);

			if (!$scan['done']) {
				return ['done' => false, 'message' => $scan['message']];
			}

			$services->state()->set('export.phase', 'commit');

			return ['done' => false, 'message' => $scan['message']];
		}

		$result = $services->committer()->commitDirty($budget);

		if ($result['done']) {
			$services->state()->delete('export.phase');
		}

		return ['done' => $result['done'], 'message' => $result['message']];
	}

	/**
	 * @return  array{done: bool, message: string}
	 */
	private function push(Services $services, Budget $budget): array
	{
		$transfer = $services->workspace()->transfer();

		if ($transfer === null) {
			return ['done' => true, 'message' => Text::_('COM_GITINSYNC_PUSH_NOT_IN_MODE')];
		}

		$services->committer()->commitDirty($budget);
		$result = $transfer->push($services->workspace()->branch(), $budget);

		return ['done' => $result['done'], 'message' => $result['message']];
	}

	/**
	 * @return  array{done: bool, message: string, operation?: int}
	 */
	private function pull(Services $services, Budget $budget, User $user): array
	{
		$result = $services->operations()->pull($user, $budget);

		return ['done' => $result['done'], 'message' => $result['message'], 'operation' => $result['operation']];
	}

	private function user(string $action): User
	{
		$user = $this->app->getIdentity();

		if ($user === null || !$user->authorise($action, 'com_gitinsync')) {
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		return $user;
	}

	private function services(): Services
	{
		$component = $this->app->bootComponent('com_gitinsync');

		if (!$component instanceof GitInSyncComponent) {
			throw new \RuntimeException('GitInSync is not installed correctly.');
		}

		return $component->services();
	}
}

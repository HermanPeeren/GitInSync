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
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Yepr\Component\GitInSync\Administrator\Extension\GitInSyncComponent;
use Yepr\Component\GitInSync\Administrator\Git\GitObject;
use Yepr\Component\GitInSync\Administrator\Job\Budget;
use Yepr\Component\GitInSync\Administrator\Service\Services;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Restore, revert, cherry-pick, merge, switch and branch management.
 *
 * All of these change the site's content, so they need "Apply versions"
 * (gitinsync.apply). Each starts an operation and goes to its screen, which resolves
 * conflicts and applies it in portions.
 */
final class OperationController extends BaseController
{
	public function restore(): void
	{
		$this->start(fn (Services $s, User $u): int => $s->operations()->restore(
			$this->input->getString('key', ''),
			$this->commitId('commit'),
			$u
		));
	}

	public function revert(): void
	{
		$this->start(fn (Services $s, User $u): int => $s->operations()->revert($this->commitId('commit'), $u));
	}

	public function cherrypick(): void
	{
		$this->start(fn (Services $s, User $u): int => $s->operations()->cherryPick($this->commitId('commit'), $u));
	}

	public function merge(): void
	{
		$this->start(function (Services $s, User $u): int {
			$branch = $this->input->getString('branch', '');
			$head   = $s->workspace()->repository()->head($branch) ?? throw new \RuntimeException(Text::sprintf('COM_GITINSYNC_NO_BRANCH', $branch));

			return $s->operations()->merge($head, $branch, $u);
		});
	}

	public function checkout(): void
	{
		$this->start(fn (Services $s, User $u): int => $s->operations()->checkout($this->input->getString('branch', ''), $u));
	}

	public function createbranch(): void
	{
		$this->checkToken();

		try {
			$this->user('gitinsync.apply');
			$from = $this->input->getString('from', '');
			$this->services()->operations()->createBranch($this->input->getString('name', ''), GitObject::isId($from) ? $from : null);
			$this->setMessage(Text::_('COM_GITINSYNC_BRANCH_CREATED'));
		} catch (\Throwable $e) {
			$this->setMessage($e->getMessage(), 'error');
		}

		$this->setRedirect('index.php?option=com_gitinsync&view=branches');
	}

	public function deletebranch(): void
	{
		$this->checkToken();

		try {
			$this->user('gitinsync.apply');
			$this->services()->operations()->deleteBranch($this->input->getString('branch', ''));
			$this->setMessage(Text::_('COM_GITINSYNC_BRANCH_DELETED'));
		} catch (\Throwable $e) {
			$this->setMessage($e->getMessage(), 'error');
		}

		$this->setRedirect('index.php?option=com_gitinsync&view=branches');
	}

	public function resolve(): void
	{
		$this->checkToken();
		$id = $this->input->getInt('id');

		try {
			$user    = $this->user('gitinsync.apply');
			$choices = [];

			foreach ((array) $this->input->post->get('conflict', [], 'array') as $index => $choice) {
				if (!\is_array($choice)) {
					continue;
				}

				$files = [];

				// Raw: the texts are HTML and JSON, filtered when they are saved into Joomla.
				foreach ((array) ($this->input->post->getRaw('conflictfiles')[$index] ?? []) as $file => $text) {
					$files[(string) $file] = (string) $text;
				}

				$choices[(int) $index] = ['choice' => (string) ($choice['choice'] ?? ''), 'files' => $files];
			}

			$this->services()->operations()->resolve($id, $choices, $user);
		} catch (\Throwable $e) {
			$this->setMessage($e->getMessage(), 'error');
		}

		$this->setRedirect('index.php?option=com_gitinsync&view=operation&id=' . $id);
	}

	public function abort(): void
	{
		$this->checkToken();
		$id = $this->input->getInt('id');

		try {
			$this->user('gitinsync.apply');
			$this->services()->operations()->abort($id);
		} catch (\Throwable $e) {
			$this->setMessage($e->getMessage(), 'error');
		}

		$this->setRedirect('index.php?option=com_gitinsync&view=operation&id=' . $id);
	}

	/**
	 * One portion of applying an operation, as JSON.
	 */
	public function step(): void
	{
		try {
			if (!Session::checkToken()) {
				throw new \RuntimeException(Text::_('JINVALID_TOKEN'));
			}

			$user     = $this->user('gitinsync.apply');
			$services = $this->services();
			$result   = $services->operations()->step(
				$this->input->getInt('id'),
				new Budget(min(20, $services->settings()->timeBudget())),
				$user
			);

			$message = $result['done']
				? $result['message']
				: Text::sprintf('COM_GITINSYNC_APPLYING_PROGRESS', $result['applied'], $result['pending']);

			echo new JsonResponse(['done' => $result['done'], 'message' => $message, 'status' => $result['status']]);
		} catch (\Throwable $e) {
			echo new JsonResponse(['done' => true, 'error' => true, 'message' => $e->getMessage()], $e->getMessage(), true);
		}

		$this->app->close();
	}

	/**
	 * @param   callable(Services, User): int  $operation
	 */
	private function start(callable $operation): void
	{
		$this->checkToken();

		try {
			$id = $operation($this->services(), $this->user('gitinsync.apply'));
		} catch (\Throwable $e) {
			$this->setMessage($e->getMessage(), 'error');
			$back = $this->input->server->getString('HTTP_REFERER', '');
			$this->setRedirect($back !== '' && Uri::isInternal($back) ? $back : 'index.php?option=com_gitinsync');

			return;
		}

		if ($id === 0) {
			$this->setMessage(Text::_('COM_GITINSYNC_NOTHING_TO_DO'));
			$this->setRedirect('index.php?option=com_gitinsync&view=dashboard');

			return;
		}

		$this->setRedirect('index.php?option=com_gitinsync&view=operation&id=' . $id);
	}

	private function commitId(string $name): string
	{
		$id = strtolower($this->input->getString($name, ''));

		if (!GitObject::isId($id)) {
			throw new \RuntimeException('Not a commit id.');
		}

		return $id;
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

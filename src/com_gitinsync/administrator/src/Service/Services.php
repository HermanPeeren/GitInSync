<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Service;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Yepr\Component\GitInSync\Administrator\Content\ArticleType;
use Yepr\Component\GitInSync\Administrator\Content\CategoryType;
use Yepr\Component\GitInSync\Administrator\Content\ContentTypes;
use Yepr\Component\GitInSync\Administrator\Content\FieldGroupType;
use Yepr\Component\GitInSync\Administrator\Content\FieldType;
use Yepr\Component\GitInSync\Administrator\Content\MenuItemType;
use Yepr\Component\GitInSync\Administrator\Content\MenuType;
use Yepr\Component\GitInSync\Administrator\Content\TagType;
use Yepr\Component\GitInSync\Administrator\Remote\HttpTransport;
use Yepr\Component\GitInSync\Administrator\Remote\JoomlaHttpTransport;
use Yepr\Component\GitInSync\Administrator\Sync\Committer;
use Yepr\Component\GitInSync\Administrator\Sync\Identity;
use Yepr\Component\GitInSync\Administrator\Sync\ItemMap;
use Yepr\Component\GitInSync\Administrator\Sync\OperationStore;
use Yepr\Component\GitInSync\Administrator\Sync\Operations;
use Yepr\Component\GitInSync\Administrator\Sync\Scanner;
use Yepr\Component\GitInSync\Administrator\Sync\Settings;
use Yepr\Component\GitInSync\Administrator\Sync\StateStore;
use Yepr\Component\GitInSync\Administrator\Sync\Workspace;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The composition root of GitInSync: the only place its services are put together.
 *
 * Each service is built on first use and shared. The plugins get this object from the
 * component (bootComponent('com_gitinsync')->services()), so a request that commits
 * nothing builds nothing.
 */
final class Services
{
	/**
	 * @var array<string, object>
	 */
	private array $instances = [];

	public function __construct(
		private readonly DatabaseInterface $db,
		private readonly UserFactoryInterface $users,
		private readonly CMSApplicationInterface $app,
		private readonly ?HttpTransport $http = null
	) {
	}

	public function settings(): Settings
	{
		return $this->once(Settings::class, fn (): Settings => new Settings(
			ComponentHelper::getParams('com_gitinsync'),
			JPATH_ROOT,
			(string) (parse_url(Uri::root(), PHP_URL_HOST) ?: 'localhost')
		));
	}

	public function state(): StateStore
	{
		return $this->once(StateStore::class, fn (): StateStore => new StateStore($this->db));
	}

	public function itemMap(): ItemMap
	{
		return $this->once(ItemMap::class, fn (): ItemMap => new ItemMap($this->db));
	}

	public function workspace(): Workspace
	{
		return $this->once(Workspace::class, fn (): Workspace => new Workspace(
			$this->settings(),
			$this->state(),
			$this->db,
			$this->http ?? new JoomlaHttpTransport()
		));
	}

	public function contentTypes(): ContentTypes
	{
		return $this->once(ContentTypes::class, function (): ContentTypes {
			$models = function (string $component, string $name): AdminModel {
				$booted = $this->app->bootComponent($component);

				if (!$booted instanceof MVCFactoryServiceInterface) {
					throw new \RuntimeException($component . ' has no MVC factory.');
				}

				$model = $booted->getMVCFactory()
					->createModel($name, 'Administrator', ['ignore_request' => true]);

				if (!$model instanceof AdminModel) {
					throw new \RuntimeException(\sprintf('No %s model in %s.', $name, $component));
				}

				return $model;
			};

			$args = [$this->db, $this->itemMap(), \Closure::fromCallable($models), $this->users];

			// In the order items are applied: what is referred to first.
			return new ContentTypes([
				new TagType(...$args),
				new CategoryType(...$args),
				new FieldGroupType(...$args),
				new FieldType(...$args),
				new ArticleType(...$args),
				new MenuType(...$args),
				new MenuItemType(...$args),
			], $this->settings()->contentTypes());
		});
	}

	public function identity(): Identity
	{
		return $this->once(Identity::class, fn (): Identity => new Identity($this->settings(), $this->users));
	}

	public function committer(): Committer
	{
		return $this->once(Committer::class, fn (): Committer => new Committer(
			$this->workspace(),
			$this->contentTypes(),
			$this->itemMap(),
			$this->identity(),
			$this->state()
		));
	}

	public function scanner(): Scanner
	{
		return $this->once(Scanner::class, fn (): Scanner => new Scanner(
			$this->workspace(),
			$this->contentTypes(),
			$this->itemMap(),
			$this->committer(),
			$this->state()
		));
	}

	public function operationStore(): OperationStore
	{
		return $this->once(OperationStore::class, fn (): OperationStore => new OperationStore($this->db));
	}

	public function operations(): Operations
	{
		return $this->once(Operations::class, fn (): Operations => new Operations(
			$this->workspace(),
			$this->contentTypes(),
			$this->itemMap(),
			$this->identity(),
			$this->state(),
			$this->operationStore(),
			$this->committer()
		));
	}

	/**
	 * @template T of object
	 *
	 * @param   class-string<T>  $id
	 * @param   callable(): T    $factory
	 *
	 * @return  T
	 */
	private function once(string $id, callable $factory): object
	{
		/** @var T */
		return $this->instances[$id] ??= $factory();
	}
}

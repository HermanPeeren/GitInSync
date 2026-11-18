<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Content\GitInSync\Extension;

use Joomla\CMS\Event\Model\AfterDeleteEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Model\AfterChangeStateEvent;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Yepr\Component\GitInSync\Administrator\Extension\GitInSyncComponent;
use Yepr\Component\GitInSync\Administrator\Job\Budget;
use Yepr\Component\GitInSync\Administrator\Service\Services;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Tells GitInSync what changed.
 *
 * A save, a deletion or a change of state marks the item as changed; the commit follows in
 * the scheduled task, or right after the response when "commit directly after saving" is
 * on. The edit forms get a GitInSync tab: "exclude from git" and the item's history.
 */
final class GitInSync extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Event contexts => GitInSync's content type.
	 */
	private const CONTEXTS = [
		'com_content.article'     => 'article',
		'com_content.form'        => 'article',
		'com_categories.category' => 'category',
		'com_fields.field'        => 'field',
		'com_fields.group'        => 'fieldgroup',
		'com_menus.menu'          => 'menu',
		'com_menus.item'          => 'menuitem',
		'com_tags.tag'            => 'tag',
	];

	/**
	 * @var boolean
	 */
	protected $autoloadLanguage = true;

	private bool $commitScheduled = false;

	private ?Services $services = null;

	/**
	 * @return  array<string, string>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onContentAfterSave'    => 'afterSave',
			'onContentAfterDelete'  => 'afterDelete',
			'onContentChangeState'  => 'changeState',
			'onContentPrepareForm'  => 'prepareForm',
			'onContentPrepareData'  => 'prepareData',
		];
	}

	public function afterSave(AfterSaveEvent $event): void
	{
		$type = $this->type($event->getContext());
		$id   = (int) ($event->getItem()->id ?? 0);

		if ($type === null || $id <= 0) {
			return;
		}

		$services = $this->services();
		$data     = $event->getData();

		if (\is_array($data) && isset($data['gitinsync']) && \is_array($data['gitinsync']) && \array_key_exists('exclude', $data['gitinsync'])) {
			$services->itemMap()->setExcluded($type, $id, (bool) $data['gitinsync']['exclude']);
		}

		$services->itemMap()->markDirty($type, $id);
		$this->scheduleCommit();
	}

	public function afterDelete(AfterDeleteEvent $event): void
	{
		$type = $this->type($event->getContext());
		$id   = (int) ($event->getItem()->id ?? 0);

		if ($type !== null && $id > 0) {
			$this->services()->itemMap()->markDirty($type, $id);
			$this->scheduleCommit();
		}
	}

	public function changeState(AfterChangeStateEvent $event): void
	{
		$type = $this->type($event->getContext());

		if ($type === null) {
			return;
		}

		foreach ($event->getPks() as $id) {
			if ((int) $id > 0) {
				$this->services()->itemMap()->markDirty($type, (int) $id);
			}
		}

		$this->scheduleCommit();
	}

	/**
	 * Adds the GitInSync tab to the edit forms of the content GitInSync keeps.
	 */
	public function prepareForm(PrepareFormEvent $event): void
	{
		$form = $event->getForm();
		$type = $this->typeOfForm($form->getName());

		if ($type === null || !$this->getApplication()?->isClient('administrator')) {
			return;
		}

		$this->loadLanguage();
		Form::addFormPath(JPATH_PLUGINS . '/content/gitinsync/forms');
		FormHelper::addFieldPrefix('Yepr\\Component\\GitInSync\\Administrator\\Field');

		$form->loadFile('gitinsync', false);
		$form->setFieldAttribute('history', 'contenttype', $type, 'gitinsync');
	}

	/**
	 * Fills in the exclude switch.
	 */
	public function prepareData(PrepareDataEvent $event): void
	{
		$type = $this->type($event->getContext());
		$data = $event->getData();

		if ($type === null) {
			return;
		}

		$id = (int) (\is_array($data) ? ($data['id'] ?? 0) : ($data->id ?? 0));

		if ($id <= 0) {
			return;
		}

		$excluded = $this->services()->itemMap()->isExcluded($type, $id) ? 1 : 0;

		if (\is_array($data)) {
			$data['gitinsync'] = ['exclude' => $excluded];
			$event->updateData($data);
		} elseif ($data instanceof \stdClass) {
			$data->gitinsync = ['exclude' => $excluded];
		}
	}

	private function type(string $context): ?string
	{
		$type = self::CONTEXTS[$context] ?? null;

		if ($type === null || !$this->installed()) {
			return null;
		}

		return $this->services()->contentTypes()->isEnabled($type) ? $type : null;
	}

	private function typeOfForm(string $name): ?string
	{
		$type = match (true) {
			$name === 'com_content.article'                      => 'article',
			$name === 'com_categories.categorycom_content'       => 'category',
			str_starts_with($name, 'com_fields.field.com_content.') => 'field',
			str_starts_with($name, 'com_fields.group.com_content.') => 'fieldgroup',
			$name === 'com_menus.menu'                           => 'menu',
			$name === 'com_menus.item'                           => 'menuitem',
			$name === 'com_tags.tag'                             => 'tag',
			default                                              => null,
		};

		if ($type === null || !$this->installed()) {
			return null;
		}

		return $this->services()->contentTypes()->isEnabled($type) ? $type : null;
	}

	/**
	 * With "commit directly after saving": commits once the response has been sent, so the
	 * editor doesn't wait for it (where the server allows ending the response early).
	 */
	private function scheduleCommit(): void
	{
		if ($this->commitScheduled || !$this->services()->settings()->commitOnSave()) {
			return;
		}

		$this->commitScheduled = true;
		$services              = $this->services();

		register_shutdown_function(static function () use ($services): void {
			if (\function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
			}

			try {
				$services->committer()->commitDirty(new Budget(10));
			} catch (\Throwable) {
				// Stays marked; the scheduled task commits it.
			}
		});
	}

	private function installed(): bool
	{
		return $this->getApplication()?->bootComponent('com_gitinsync') instanceof GitInSyncComponent;
	}

	private function services(): Services
	{
		if ($this->services === null) {
			$component = $this->getApplication()?->bootComponent('com_gitinsync');

			if (!$component instanceof GitInSyncComponent) {
				throw new \RuntimeException('GitInSync is not installed correctly.');
			}

			$this->services = $component->services();
		}

		return $this->services;
	}
}

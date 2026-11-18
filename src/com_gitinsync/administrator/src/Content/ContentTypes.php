<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Content;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The content types GitInSync knows, in the order they must be applied: what is referred
 * to before what refers to it (categories before articles, menus before menu items).
 */
final class ContentTypes
{
	/**
	 * @var array<string, ContentType>
	 */
	private array $types = [];

	/**
	 * @param   ContentType[]  $types  In apply order.
	 * @param   string[]       $enabled
	 */
	public function __construct(array $types, private readonly array $enabled)
	{
		foreach ($types as $type) {
			$this->types[$type->name()] = $type;
		}
	}

	/**
	 * The enabled types, in apply order.
	 *
	 * @return  array<string, ContentType>
	 */
	public function enabled(): array
	{
		return array_filter($this->types, fn (ContentType $type): bool => \in_array($type->name(), $this->enabled, true));
	}

	/**
	 * @return  array<string, ContentType>
	 */
	public function all(): array
	{
		return $this->types;
	}

	public function get(string $name): ?ContentType
	{
		return $this->types[$name] ?? null;
	}

	public function isEnabled(string $name): bool
	{
		return isset($this->types[$name]) && \in_array($name, $this->enabled, true);
	}

	public function byContext(string $context): ?ContentType
	{
		foreach ($this->enabled() as $type) {
			if (\in_array($context, $type->contexts(), true)) {
				return $type;
			}
		}

		return null;
	}

	public function byDirectory(string $directory): ?ContentType
	{
		foreach ($this->types as $type) {
			if ($type->directory() === $directory) {
				return $type;
			}
		}

		return null;
	}

	public function order(string $name): int
	{
		$position = array_search($name, array_keys($this->types), true);

		return $position === false ? PHP_INT_MAX : $position;
	}
}

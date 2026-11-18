<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Git;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The author or committer line of a commit: "Name <email> 1763460000 +0000".
 */
final class Signature
{
	public readonly string $name;

	public readonly string $email;

	/**
	 * @param   string   $offset     "+0000", "-0530", ...
	 */
	public function __construct(string $name, string $email, public readonly int $timestamp, public readonly string $offset = '+0000')
	{
		// Git ends the name at "<" and the email at ">"; a newline would end the header.
		$this->name  = trim(str_replace(['<', '>', "\n", "\r"], '', $name));
		$this->email = trim(str_replace(['<', '>', "\n", "\r"], '', $email));

		if (!preg_match('/^[+-]\d{4}$/', $offset)) {
			throw new \InvalidArgumentException(\sprintf('Bad time zone offset "%s".', $offset));
		}
	}

	public function format(): string
	{
		return \sprintf('%s <%s> %d %s', $this->name, $this->email, $this->timestamp, $this->offset);
	}

	public static function parse(string $line): self
	{
		if (!preg_match('/^(.*?) ?<([^>]*)> (-?\d+) ([+-]\d{4})$/', $line, $m)) {
			throw new CorruptObject(\sprintf('Bad signature "%s".', $line));
		}

		return new self($m[1], $m[2], (int) $m[3], $m[4]);
	}

	/**
	 * ISO 8601 with the offset, as GitHub's API takes and gives it.
	 */
	public function iso8601(): string
	{
		$date = new \DateTimeImmutable('@' . $this->timestamp);
		$zone = new \DateTimeZone(substr($this->offset, 0, 3) . ':' . substr($this->offset, 3));

		return $date->setTimezone($zone)->format('Y-m-d\TH:i:sP');
	}

	public static function fromIso8601(string $name, string $email, string $date): self
	{
		$parsed = new \DateTimeImmutable($date);

		return new self($name, $email, $parsed->getTimestamp(), str_replace(':', '', $parsed->format('P')));
	}
}

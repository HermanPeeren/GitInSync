<?php

/**
 * The Joomla constants GitInSync reads.
 *
 * Joomla defines these when an application boots, so they exist at runtime but in no
 * file an analyser reads. Only the ones GitInSync uses are here.
 */

declare(strict_types=1);

\define('JVERSION', '6.2.0');
\define('JPATH_ROOT', \dirname(__DIR__) . '/joomla');
\define('JPATH_PLUGINS', JPATH_ROOT . '/plugins');
\define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');

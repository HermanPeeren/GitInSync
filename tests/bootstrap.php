<?php

/**
 * What the suite needs before it can load any of GitInSync's classes.
 *
 * Every Joomla source file opens with `defined('_JEXEC') or die;`. Under PHPUnit that
 * constant is not defined, so loading such a class would `die` and end the run without a
 * word.
 *
 * The git layer, the diff and the merge are plain PHP. The tests of the stores and of the
 * GitHub transfer use Joomla's database classes (on SQLite), from the Joomla in /joomla.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects
require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../joomla/libraries/vendor/autoload.php')) {
    require __DIR__ . '/../joomla/libraries/vendor/autoload.php';
}

if (!\defined('_JEXEC')) {
    \define('_JEXEC', 1);
}
// phpcs:enable PSR1.Files.SideEffects

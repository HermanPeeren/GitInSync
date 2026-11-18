<?php

declare(strict_types=1);

namespace Yepr\GitInSync\Tests\Support;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\Sqlite\SqliteDriver;

/**
 * An in-memory SQLite database with GitInSync's tables, through Joomla's own database
 * classes: the stores' queries run as they do on a site.
 */
final class TestDatabase
{
    public static function create(): DatabaseInterface
    {
        $db = new SqliteDriver(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'jos_']);

        foreach (
            [
                'CREATE TABLE jos_gitinsync_items (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, item_id INTEGER NOT NULL,'
                . ' uuid TEXT NOT NULL, dirty INTEGER NOT NULL DEFAULT 0, excluded INTEGER NOT NULL DEFAULT 0,'
                . ' UNIQUE (type, item_id), UNIQUE (type, uuid))',
                'CREATE TABLE jos_gitinsync_state (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
                'CREATE TABLE jos_gitinsync_objects (id TEXT PRIMARY KEY, type TEXT NOT NULL, data TEXT NOT NULL)',
                'CREATE TABLE jos_gitinsync_operations (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, status TEXT NOT NULL,'
                . " branch TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', data TEXT NOT NULL, message TEXT NOT NULL,"
                . ' created TEXT NOT NULL, created_by INTEGER NOT NULL DEFAULT 0)',
                'CREATE TABLE jos_gitinsync_pushed (id TEXT PRIMARY KEY)',
            ] as $sql
        ) {
            $db->setQuery($sql)->execute();
        }

        return $db;
    }
}

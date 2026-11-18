<?php

/**
 * Assembles the installable package.
 *
 *   php build/build.php   -> build/pkg_gitinsync-<version>.zip
 *
 * The repository already mirrors the package layout, so this zips `src/`: the package
 * manifest and install script at the root, and one folder per extension. Joomla installs
 * a package's extensions from folders as well as from zips.
 */

declare(strict_types=1);

$root     = \dirname(__DIR__);
$manifest = simplexml_load_file($root . '/src/pkg_gitinsync.xml');

if ($manifest === false) {
    fwrite(STDERR, "Cannot read the package manifest.\n");
    exit(1);
}

$version = trim((string) $manifest->version);

if ($version === '') {
    fwrite(STDERR, "The package manifest needs a version.\n");
    exit(1);
}

// Every extension carries the package's version.
foreach (['com_gitinsync/gitinsync.xml', 'plg_content_gitinsync/gitinsync.xml', 'plg_task_gitinsync/gitinsync.xml'] as $file) {
    $child = simplexml_load_file($root . '/src/' . $file);

    if ($child === false || trim((string) $child->version) !== $version) {
        fwrite(STDERR, "src/{$file} must have version {$version}.\n");
        exit(1);
    }
}

$zipPath = $root . '/build/pkg_gitinsync-' . $version . '.zip';

if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "Cannot create {$zipPath}.\n");
    exit(1);
}

$source = $root . '/src';
$files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
$added  = 0;

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    // Forward slashes always: a backslash in a zip entry becomes part of a file name on
    // Linux, and the extension then fails to load.
    $entry = str_replace('\\', '/', substr($file->getPathname(), \strlen($source) + 1));

    $zip->addFile($file->getPathname(), $entry);
    $added++;
}

$zip->close();

printf("%s\n  %d files, %.1f KB\n", basename($zipPath), $added, filesize($zipPath) / 1024);

# Developing GitInSync

## Layout

```
src/
  pkg_gitinsync.xml, script.php      the package and its install script
  com_gitinsync/                     the component: all the logic and the screens
    administrator/src/
      Git/        git's object format, a bare repository on disk, history, ancestry
      Diff/       line diff and three-way merge of text (diff3)
      Merge/      three-way merge of a whole tree, per item and per JSON key
      Content/    the content types: export to files, import through Joomla's models
      Sync/       settings, item map, committer, scanner, operations (restore, merge, ...)
      Remote/     GitHub's REST API: client, objects, push and fetch
      Job/        time budgets for work in portions
      Service/    Services: the composition root
      Controller/, View/, Field/
    administrator/tmpl/              the screens
    media/js/run.js                  runs a job in portions from the browser
  plg_content_gitinsync/             marks saved items; the GitInSync tab in edit forms
  plg_task_gitinsync/                scheduled tasks: commit (+ scan, + push), pull
tests/
  Unit/       PHPUnit; the git tests check GitInSync's output with the git program
  Support/    a stand-in for GitHub backed by real git, an SQLite test database
  cypress/    end-to-end tests against the site in /joomla
build/build.php                      builds build/pkg_gitinsync-<version>.zip
tools/install-local.php              installs the build into /joomla
```

Both plugins are lazy proxies (`$container->lazy()`, Joomla 6.1): they are only built when
one of their events fires, and they only boot the component when the event is about
content GitInSync keeps.

## The development site

`/joomla` is git-ignored. It holds the latest stable Joomla, installed with:

```
php joomla/installation/joomla.php install -n --site-name=GitInSync \
  --admin-user=Admin --admin-username=admin --admin-password=<a local password> \
  --admin-email=admin@example.invalid --db-type=mysqli --db-host=localhost \
  --db-user=root --db-pass="" --db-name=jlatest-gitinsync --db-prefix=jgi_
```

Choose a password for this local site only, never one used on a real site. Copy
`cypress.env.json.dist` to `cypress.env.json` (git-ignored) and point it at the site.

Set the option "Repository folder" to `../content-repository`: the repository then lands in
the git-ignored `/content-repository` of this repository.

For tests against GitHub itself, use a private test repository and a fine-grained token
for that repository only; keep the token in the git-ignored `tests/github.env.json`.

## Commands

```
composer test           PHPUnit
composer analyse        PHPStan, level 8
composer cs             PHP_CodeSniffer
composer build          build/pkg_gitinsync-<version>.zip
composer install-local  build, then install into /joomla
npx cypress run         end-to-end tests
```

The git tests need the git program; the package never does.

## Database

| Table | Holds |
|---|---|
| `#__gitinsync_items` | type + local id ⇄ UUID, the "changed" mark, the exclude flag |
| `#__gitinsync_state` | small values: the site's branch, cursors, locks |
| `#__gitinsync_objects` | GitHub-only mode: objects read from GitHub; during a fetch: commits read so far |
| `#__gitinsync_operations` | restores, reverts, merges, ...: their conflicts and progress |
| `#__gitinsync_pushed` | objects uploaded to GitHub for a push that is not finished yet |

## Releasing

1. Bump the version in `src/pkg_gitinsync.xml` and the three extension manifests.
2. Add `sql/updates/{mysql,postgresql}/<version>.sql`, even if it only holds a comment.
3. Go through the checklist in `docs/security-review.md` and update its "Last full review"
   line.
4. Tag `v<version>` and push the tag. The release workflow checks all of this, runs the
   tests and attaches the package to a GitHub release.

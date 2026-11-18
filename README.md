# GitInSync

GitInSync keeps the content of a Joomla site in git: articles, categories, custom fields,
tags and menus. Every save becomes a commit, so you get the full history of every item,
can restore an old version, revert a change, work on a branch and merge it back. The
repository can live on the server, be pushed to GitHub, or be on GitHub only.

It is pure PHP. It never runs the git program and needs no command line, so it works on
any shared host. Everything is done from Joomla's administrator.

## What it does

- **Commits content changes.** Saving an item in Joomla marks it; the scheduled task (or,
  if you want, the save itself) commits it with the editor as author. A scan finds
  changes that fired no event, such as batch actions and imports.
- **Readable files.** Each item is a folder: `articles/<uuid>-<alias>/article.json` plus
  one `.html` file per HTML column (`introtext.html`, `fulltext.html`). JSON has sorted
  keys, one per line, so diffs are small and meaningful. Items refer to each other by
  UUID, so a repository can be applied to another site.
- **History, restore, revert, cherry-pick.** Per item and per commit, from the GitInSync
  screens and from a GitInSync tab in each edit form.
- **Branches and merges.** A site follows one branch. A staging site can switch branches;
  merging is done per item and per field, with HTML merged line by line. Conflicts are
  shown side by side and resolved in the browser.
- **GitHub.** Three modes: a local repository, a local repository pushed to GitHub, or
  GitHub only through its REST API. Commits get the same ids on GitHub as on the site, so
  both share one history. Pull requests merged on GitHub can be pulled into the site.
- **Privacy.** Unpublished and non-public content is only stored when the repository is
  private (or as you choose). Each item can be excluded. Commit authors can get a no-reply
  email address.

## Using it

1. Install `pkg_gitinsync-<version>.zip` through System → Install → Extensions.
2. Open Components → GitInSync → Options. Choose where the repository lives. For GitHub,
   create a fine-grained token for that one repository with "Contents: read and write" and
   "Metadata: read".
3. On the dashboard, click **Export all content** for the first commit.
4. Create a scheduled task "GitInSync: commit changes" (and, with GitHub, "GitInSync: pull
   from GitHub"). Long work is done in portions within the task's time budget.

Excluding an item removes it from the branch from then on. Earlier commits still contain
it, also on GitHub once they have been pushed.

## Requirements

- Joomla 6.1 or later (the plugins are loaded lazily, which came in 6.1).
- PHP 8.3 or later with zlib.
- For GitHub: outgoing HTTPS from the server.

## Development

See [docs/development.md](docs/development.md) and [docs/plan.md](docs/plan.md).

## Licence

GNU General Public License version 3 or later; see [LICENSE](LICENSE).

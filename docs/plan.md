# GitInSync: plan

Draft of 18 November 2026. Background research is in [research.md](research.md). The
**design questions** at the end must be answered before building. The recommended answer
is listed first each time.

## Goal

GitInSync puts a Joomla site's content in git, without leaving Joomla:

- **Versioning**: every change to an article, category, field, menu item or tag becomes
  a commit, with the editor as author.
- **Branching**: content can be worked on in a branch and merged later. A staging site on
  branch `staging` with the live site on `main`, or a redesign of a section, or a
  translation round.
- **Restoring and reverting**: put back an item as it was at a chosen commit, undo one
  commit, or take over a commit from another branch (cherry-pick).
- **A local repository, GitHub, or both**: a git repository on the server, which can be
  pushed to GitHub, or everything directly through the GitHub API.

"API" in this plan always means **GitHub's REST API** (the web services of GitHub), never
Joomla's own web services.

Everything is operated from Joomla's administrator. No command line is needed, and the
`git` program is never called, so it runs on any shared host. If a CLI is available, it
can be used, but it is never required.

## The idea in one paragraph

Joomla's database is the **working tree**. GitInSync turns content into files (one JSON
file per item, plus an `.html` file per HTML field) and commits them. The other way
round, it reads the files of a commit and writes them into Joomla through Joomla's own
models. All git operations (commit, diff, log, branch, merge, revert, cherry-pick, push,
fetch) are done by GitInSync's own PHP code, on git's own object format.

```
 Joomla database                GitInSync                         git
 ───────────────               ──────────                        ─────
 articles, categories  ──►  serialise → files  ──►  blobs, trees, commits ──► local .git
 fields, menu items    ◄──  apply files → models ◄──  checkout / restore     ◄─► GitHub (API)
 tags                       (diff, merge, revert)
```

## Where the objects live

All git logic (diff, three-way merge, revert, log of a path) is written once, against an
**object store** interface: read and write blobs, trees and commits, read and move
references. There are two implementations.

1. **Local repository.** A real git repository on disk (`.git` with loose objects,
   compressed with zlib, and `refs/heads/*`). Real git can read it: anyone who has shell
   access can `git log` or `git clone` it, but GitInSync itself doesn't need that. It has
   to be outside the web root, or behind a deny-all `.htaccess`.
2. **GitHub through its API.** The Git Data API (`/git/blobs`, `/git/trees`,
   `/git/commits`, `/git/refs`) is a remote object store. Objects never change once
   written, so every object read is cached in a database table. Diffs and logs don't
   have to fetch the same object twice.

**Push and fetch** copy missing objects between the two stores, in portions. A git
object's SHA-1 depends only on its content. So a commit made locally and created again
through the API, with the same tree, parents, author, committer and dates, gets the same
SHA. A local commit is therefore "pushed" by recreating it through the API, and the
result is checked against the local SHA. Fetching from GitHub (for example after a pull
request was merged there) works the same way back. No git protocol and no packfiles are
needed.

The modes in the options:

| Mode | Repository | Remote |
|---|---|---|
| Local | `.git` on the server | none |
| Local + GitHub | `.git` on the server | GitHub: push and fetch from the administrator or as a task |
| GitHub only | object cache in the database | GitHub is the repository |

Not chosen: `wp-php-toolkit/git` (pure PHP git with smart-HTTP push). It is version 0.x
and depends on the rest of WordPress's toolkit. The Git Data API is stable, works the
same everywhere, and needs only Joomla's HTTP client. If wanted, a smart-HTTP transport
for GitLab, Gitea or Bitbucket can be added later behind the same interface.

## Identity: a UUID per item

A branch can contain an article that `main` doesn't have, and a staging site has
different IDs from the live site. Database IDs therefore can't be the identity in git.

- Each item gets a **UUID**, kept in GitInSync's own table. A mapping table (site,
  content type, UUID ↔ local ID) remembers which local item it is.
- **References between items** are stored as UUIDs: an article's category, its tags, a
  menu item's link (`index.php?option=com_content&view=article&id=12`), a parent
  category, a field's group. On applying they are translated back into local IDs.
- An item that arrives from git with an unknown UUID is created. Joomla gives it a new ID,
  and the mapping is recorded.
- Paths in the repository contain the UUID plus the alias, for readability:
  `articles/0b6e…-my-article/`. A changed alias moves the folder, and git shows that as a
  rename.

## Repository layout

```
README.md                                 generated: which site, which content, how to read it
gitinsync.json                            format version, content types, settings that matter for reading it back
articles/<uuid>-<alias>/
    article.json                          all columns except volatile ones, references as UUIDs,
                                          custom field values, tags, associations
    introtext.html
    fulltext.html
categories/<extension>/<uuid>-<alias>/
    category.json
    description.html
fields/<context>/<uuid>-<name>.json       field definitions (com_content.article, com_content.categories, …)
fieldgroups/<context>/<uuid>-<title>.json
menus/<menutype>/menu.json
menus/<menutype>/items/<uuid>-<alias>.json
tags/<uuid>-<alias>/
    tag.json
    description.html
```

**Serialisation** must give the same bytes for the same content, so git shows only real
changes:

- JSON with sorted keys, two spaces, `\n`, UTF-8 without escaped slashes or Unicode.
  JSON-in-a-column (`attribs`, `metadata`, `params`, `images`, `urls`) is decoded and
  stored as nested JSON, so a change shows on its own line.
- HTML stays **exactly as stored**: no reformatting, so applying it back gives the
  same bytes. Only the line ending is normalised to `\n`, and the original line ending
  is remembered.
- Left out: `hits`, `checked_out`, `checked_out_time`, `version`, `asset_id`, `modified`,
  `modified_by`. The last two go into the commit as author and date instead. Left-out
  fields are kept as they are when applying.

## Joomla structure: a component as base, plugins where needed

Package `pkg_gitinsync`:

| Extension | Role |
|---|---|
| **com_gitinsync** (administrator) | All logic and all screens. Plugins call its services through `bootComponent('com_gitinsync')`. |
| **plg_content_gitinsync** | Content events: marks changed items as dirty; adds an "Exclude from git" field to the edit forms; with "commit directly after saving" on, commits right after the response is sent. |
| **plg_task_gitinsync** | Task types: "Commit changes" (dirty queue plus a check for changes the events missed), "Push / fetch", "Full export". In portions, with a time budget and `WILL_RESUME`. |
| plg_quickicon_gitinsync (optional) | Dashboard icon: uncommitted changes, branch, ahead/behind GitHub. |
| plg_console_gitinsync (later, optional) | `gitinsync:commit`, `gitinsync:push`, … for those who do have a CLI. |

Namespaces: `Yepr\Component\GitInSync\Administrator`, `Yepr\Plugin\Content\GitInSync`,
`Yepr\Plugin\Task\GitInSync`.

### Component screens

- **Dashboard**: mode, branch, last commit, uncommitted changes, ahead/behind GitHub,
  "Test connection", "Commit now", "Push", "Fetch". Long operations run in portions over
  AJAX with a progress bar, so they work without a CLI and within PHP's time limit.
- **Changes**: items with uncommitted changes, with a diff per item; commit everything or
  a selection, with a message.
- **History**: the commit log (of the branch, or of one item), with author, date and
  changed items. Per commit: view the diff, **revert** it, **cherry-pick** it to the
  current branch. Per item version: **restore** it.
- **Branches**: create from any commit, switch, merge into the current branch, delete.
  Conflicts are shown per item and per field, and you choose a side or edit by hand.
- **Items**: everything that is tracked, with its UUID, path, last commit and the
  exclude switch.
- **Options**: mode, local path, GitHub owner, repository, branch, token, commit identity,
  content types, filters (see below), "commit directly after saving", time budget.

From an item's edit screen (article, category, …), a "GitInSync" tab shows that item's
history, with "restore".

### Tables

| Table | What |
|---|---|
| `#__gitinsync_items` | UUID ↔ content type + local ID, path, blob SHAs of the last commit, dirty flag, excluded flag |
| `#__gitinsync_refs` | in GitHub-only mode: branches and their SHAs; locally they're in `.git` |
| `#__gitinsync_objects` | in GitHub-only mode: cache of fetched objects |
| `#__gitinsync_jobs` | state of long operations (export, push, fetch, merge, checkout) for running in portions |
| `#__gitinsync_conflicts` | open merge, revert or cherry-pick conflicts, per item and field |

### Events covered

| Content type | Contexts | Events |
|---|---|---|
| Articles | `com_content.article` (also from the frontend form) | `onContentAfterSave`, `onContentAfterDelete`, `onContentChangeState` |
| Categories | `com_categories.category` | the same |
| Fields, field groups | `com_fields.field`, `com_fields.group` | the same |
| Menus, menu items | `com_menus.menu`, `com_menus.item` | the same |
| Tags | `com_tags.tag` | the same |
| Field values | stored by plg_system_fields after the item | covered because the event only marks the item dirty; it is read later |

Batch actions, ordering by drag and drop, imports and direct database changes don't
always fire events. The task therefore also compares each item's current serialisation
with the last committed blob SHA (a cheap check per portion), and commits what it finds.

## What is stored: filters

- **Content types**: per type on or off. Version 1 has articles, categories, fields and
  field groups, menus and menu items, and tags.
- **Publication state**: published, unpublished, archived, trashed: each on or off.
  Plus "Only when the GitHub repository is private" for the non-published states. That
  is checked through the API and shown on the dashboard.
- **Access level**: by default only Public, when the repository is public. Content for
  Registered or Special users in a public repository is a leak.
- **Per item**: "Exclude from git". When an item becomes excluded or unpublished, its
  files are deleted in the next commit. The options and the item form warn that **earlier
  commits still have it**, on GitHub too. Removing it from history means rewriting
  history, which is out of scope.
- An item that is filtered out is never deleted from Joomla by checkout, merge or
  revert. Those operations only touch items that are in the repository.

## Writing back into Joomla

Restore, revert, cherry-pick, checkout of a branch and merge all end the same way: a set
of files is turned into items and **saved through Joomla's own models** (`ArticleModel`,
`CategoryModel`, `ItemModel` of com_menus, `TagModel`, `FieldModel`). That way ACL,
workflow, content history, Smart Search, the nested set of categories and menus, and
other extensions' plugins all behave as with a normal save.

- **Permissions**: the current user needs edit rights on each item. Missing rights are
  reported per item, and that item is skipped.
- **Untrusted input**: content from git can come from anyone who can push to the
  repository. HTML is filtered with Joomla's text filters for the user applying it, as
  in a normal save. Values are validated against the model's form.
- **Order**: categories before articles, field groups before fields, menus before menu
  items, parents before children.
- **Deletion**: an item deleted in git is trashed in Joomla, not deleted for good.
- **Checked-out items** are skipped and reported.
- **Echo**: after applying, the item's serialisation matches the blob in git, so the
  `onContentAfterSave` from applying doesn't cause a new commit. A revert, merge or
  cherry-pick makes its own commit with a proper message
  ("Revert "Update article Foo"", "Merge branch 'staging'").

## Branching

Two ways to use branches; both are possible in the design.

1. **A site follows a branch** (recommended as the main use). Each site has one branch in
   its options. The staging site commits to `staging`, the live site to `main`.
   Merging is done in the administrator ("merge staging into main" on the live site), or
   as a pull request on GitHub, and then a "fetch and apply" on the site.
   Because of UUIDs, content created on staging arrives on live as new items.
2. **Switching branches on one site.** Checking out another branch writes that branch's
   content into the site. Before that, uncommitted changes are committed (or the switch is
   refused). The site's content changes immediately, so it is meant for a staging site,
   not for a live site. The UI warns about that.

**Merging** is three-way, against the merge base:

- JSON files are merged **per field** (a JSON key), not per line. Two people changing the
  title and the category of the same article don't conflict.
- HTML files are merged per line (diff3). A conflict is shown side by side, and you choose
  ours, theirs, or edit.
- Delete vs change is a conflict, with a choice.
- An unfinished merge is kept in `#__gitinsync_conflicts`. The branch only moves when all
  conflicts are resolved; then the merge commit is made and applied.

**Revert** and **cherry-pick** are three-way merges too (with the commit's parent or the
commit as base), so they work when the item was changed after the commit.

## Commits

- One commit per saved item, with the editor as author and the item's modified date as
  the date. The full export and the "check for missed changes" make one commit per
  portion ("GitInSync: export, part 3").
- **Author identity** (options): the user's name with a no-reply address
  (`<user-id>+<username>@users.noreply.<site host>`), or the user's real email, or one
  fixed name and address. The real email in a public repository is a privacy problem, so
  no-reply is the default.
- The committer is always "GitInSync" with the site's address. That makes clear which
  site made the commit.

## GitHub

- **Token**: a fine-grained personal access token for one repository, with *Contents:
  read and write* (and *Metadata: read*, which comes with it). Stored in the component's
  options. The options form never shows the token back: it shows "token is set" and an
  empty field to replace it. A GitHub App can come later.
- **Rate limits**: 5,000 requests an hour with a token. A first export of a large site
  goes in portions. New trees are created with inline `content` (one request for many
  files), and each run stops in time when the limit gets close.
- **Repository visibility** is read through the API, and drives the filters above.

## Joomla, PHP

- Joomla 6.x and PHP 8.3 (to be decided, see the questions). The install script checks
  both.
- No Composer dependencies in the package. Zlib (`gzcompress`, `gzuncompress`) and SHA-1
  are part of PHP. HTTP is Joomla's `HttpFactory`.
- MySQL/MariaDB and PostgreSQL.

## Repository and development setup

As in earlier repositories (plg_task_finderexternal):

```
C:\wamp\www\GitInSync          → github.com/HermanPeeren/GitInSync
  src/                          the package as installed: pkg_gitinsync.xml, script.php,
                                administrator/components/com_gitinsync/, plugins/content/gitinsync/,
                                plugins/task/gitinsync/, …
  tests/Unit/                   PHPUnit: serialiser, object store, diff, diff3, merge, UUID mapping, GitHub client (fake HTTP)
  tests/Integration/            PHPUnit against the development site and a test repository on GitHub;
                                skipped without a token
  tests/cypress/                options form, exclude field, commit on save, history, restore,
                                revert, branch and merge, conflict screen
  build/build.php               zips src/ into build/pkg_gitinsync-<version>.zip (one zip per extension inside)
  tools/                        install-local.php, phpstan-bootstrap.php
  docs/                         plan.md (this plan, as decided), development.md, security-review.md
  .github/workflows/            ci.yml (test, analyse, cs, audit); release.yml (on tag v*: checks, build, release)
  joomla/                       git-ignored: Joomla 6.2.0, database jlatest-gitinsync, prefix jgi_,
                                a local-only admin login
```

- **PHPStan** level 8 against `/joomla`, **phpcs** (PSR-12 with Joomla's tabs in `src/`),
  **php-cs-fixer** for tests, build and tools, `composer audit`.
- **Test against real git** in the unit tests: the repository written by the PHP code
  must pass `git fsck`, and `git log` and `git diff` must show what is expected. Also the
  other way round: GitInSync must read a repository made by real git. Only the tests
  use the git program; the package never does.
- **A test repository on GitHub**, e.g. `HermanPeeren/gitinsync-test` (private), with a
  fine-grained token in a git-ignored file, for the integration tests and the Cypress
  specs that push and fetch.
- **Security review** document with a release checklist; the release workflow refuses a
  tag the review doesn't name.
- Attribution follows your global instructions: no Claude lines in commits, PRs or files.

## Milestones

1. **Skeleton**: repository, package with component and two plugins installing cleanly,
   build, CI, release, development site, Cypress login.
2. **Commit, locally**: serialiser and UUIDs for all v1 content types, local object
   store, dirty marking on save, the commit task, full export, filters and the exclude
   field, dashboard and changes screen.
3. **GitHub**: GitHub-only mode, push and fetch for local + GitHub, token handling,
   visibility check, rate limits.
4. **History and restore**: log per branch and per item, diff view, restore an item,
   revert a commit.
5. **Branches**: create, switch, merge with per-field JSON merge and diff3 for HTML,
   conflict screen, cherry-pick, "a site follows a branch", fetch and apply.
6. **Later**: import Joomla's existing `#__history` as the first commits; media files
   (images used in content); other components (contacts, modules, other components
   through a small exporter interface); a CLI plugin; GitLab, Gitea, Bitbucket.
   Possibly a **GitHub Action that applies a branch to a site through Joomla's web
   services** (`/api/index.php/v1/content/articles`, …), so a merge on GitHub reaches a
   site without that site having to fetch. The OpenAPI description in
   [joomla-projects/API-Endpoints](https://github.com/joomla-projects/API-Endpoints) is
   the reference for that. Version 1 doesn't need Joomla's web services.

## Design questions

The first option is the recommendation.

**Decided on 18 November 2026:** 1a (own pure-PHP object store, push and fetch through
GitHub's REST API), 2a (a site follows a branch; switching on one site for staging
sites), 3a (UUID per item with a mapping table), 4a (writing back through Joomla's
models), 5a (mark on save, commit in the task, option to commit directly after saving),
6a (published and Public only, unless the repository is private), 7a (name with a
no-reply address), 8a (Joomla 6.x, PHP 8.3), 9a (`C:\wamp\www\GitInSync`,
`HermanPeeren/GitInSync`), 10a (private `HermanPeeren/gitinsync-test` with a
fine-grained token in a git-ignored file), 11a (en-GB, and nl-NL at the first release).
All questions are answered.

1. **How to talk to git without the git program.** (a) Own pure-PHP object store, with
   push and fetch through the GitHub API, as described above. (b) `wp-php-toolkit/git`
   with git's own HTTP protocol. (c) GitHub API only in version 1, local repository
   later.
2. **Branch model.** (a) Mainly "a site follows a branch", with switching branches on one
   site possible but marked as for staging sites. (b) Only one site per branch. (c)
   Switching branches as the main way.
3. **Identity.** (a) UUID per item with a mapping table, so branches and sites can each
   have their own IDs. (b) Joomla's IDs (simpler, but only works for one site and breaks
   when two branches create items).
4. **Writing back.** (a) Through Joomla's models (ACL, workflow, history, plugins). (b)
   Directly into the tables (faster, but no events, no ACL checks, nested sets to keep
   right ourselves).
5. **When to commit.** (a) Mark on save, commit in the task, with the option "commit
   directly after saving". (b) Only by the task. (c) Always directly.
6. **Non-public content by default.** (a) Published and Public only, unless the
   repository is private, where everything is stored. (b) Everything always. (c) Only
   published and Public, always.
7. **Author identity by default.** (a) Name with a no-reply address. (b) Real email. (c)
   One fixed identity.
8. **Joomla versions.** (a) Joomla 6.x and PHP 8.3. (b) Joomla 5.4 and 6.x, with PHP 8.1.
9. **Folder and repository name.** (a) `C:\wamp\www\GitInSync` and
   `HermanPeeren/GitInSync`. (b) `pkg_gitinsync` for both.
10. **Test repository.** (a) A private `HermanPeeren/gitinsync-test`, which you create
    with a fine-grained token in a git-ignored file. (b) No GitHub tests in version 1.
11. **Languages.** (a) en-GB, and nl-NL at the first release. (b) en-GB only.

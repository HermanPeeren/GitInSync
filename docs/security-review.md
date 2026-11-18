# Security review

GitInSync is checked against the Security section of the Joomla Programmers Manual
(<https://manual.joomla.org/docs/next/security/>).

This review is a **release step**: before a version is tagged, go through the checklist at
the end. The release workflow refuses a tag whose version is not on the line below.

Last full review: 0.1.0, 2026-11-18.

## What is input here

1. **The administrator screens.** Forms and buttons of GitInSync, used by backend users
   with `core.manage` (looking, committing, exporting, pushing) and `gitinsync.apply`
   (everything that changes the site's content).
2. **The options**, set by users with `core.options`. The GitHub token is one of them.
3. **The repository.** Content comes back from git when a version is restored, a commit is
   reverted or cherry-picked, a branch is merged or switched to, or GitHub is pulled. On
   GitHub, anyone who can push to the repository (or merge a pull request) writes that
   content. It is treated as input from those people, not as trusted data.
4. **GitHub's API responses.**

There are no frontend entry points. The plugins add no routes.

## Checks

| Point | How |
|---|---|
| Access | Every controller task checks `core.manage` or `gitinsync.apply`; the connection test (which uses the stored token and may write a first commit) needs `core.options`. The display controller checks `core.manage`. The scheduled pull applies as a chosen user, who must have `gitinsync.apply`. |
| CSRF | Every task that changes something checks the form token: `checkToken()` for forms, `Session::checkToken()` for the JSON steps that `run.js` posts. |
| Content written back to Joomla | Through Joomla's own models (`save`, `publish`, `delete`), as the acting user: the same permission checks, `ComponentHelper::filterText()` on every HTML column (the user's text filter), and the models' own validation. Checked-out items are refused. |
| References between items | Resolved by UUID through `#__gitinsync_items`; an unknown reference stops that item instead of guessing. Authors that don't exist on the site are dropped. |
| Ids and names | Commit ids must be 40 hex digits (`GitObject::isId`), also inside commits read from GitHub. Branch names are checked against git's rules and kept inside `refs/heads/`, so a name cannot reach outside the repository folder. Item paths are parsed with a strict UUID pattern. Owner and repository names are checked before they go into an API URL. |
| Output | Every value in the templates goes through `$this->escape()`; numbers are cast. |
| Redirects | Only to GitInSync's own pages, or back to an internal referrer (`Uri::isInternal`). |
| SQL | Query builder with bound parameters throughout. |
| Files | The local repository lives outside the web root by default. Its folder gets a deny-all `.htaccess` and `web.config`. Ref updates use lock files; object files are written atomically. Nothing is ever executed from the repository. |
| GitHub token | A fine-grained token for one repository is advised. The options field never sends the token back to the browser. It is stored in the component's parameters (as Joomla stores other credentials). It is sent only to `api.github.com`, in the `Authorization` header. |
| Private content | Unpublished and non-public items only go into a public GitHub repository when the options say "Always". The visibility is asked from GitHub; when it cannot be determined, the repository counts as public. |
| Exclusion | Excluding an item removes it from the next commit. The exclude option and its help say that earlier commits keep it. |
| Resource limits | All long work is done in portions within a time budget (browser steps and scheduled tasks); the line diff has a size cap; GitHub objects are cached on disk or in the database, not in memory. |

## Release checklist

- [ ] New controller tasks check a token and a permission.
- [ ] New template output is escaped.
- [ ] Content written back still goes through the models and `filterText()`.
- [ ] New input from the repository or GitHub is validated before it is used in a path,
      URL, query or page.
- [ ] `composer test`, `composer analyse`, `composer cs` and `composer audit` pass.
- [ ] Update the "Last full review" line above.

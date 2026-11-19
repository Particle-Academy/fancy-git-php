# Changelog

All notable changes to `particle-academy/fancy-git` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0: breaking changes land in MINOR releases.** Read the entry, not the
> version number.

> **History before 0.2.0 is not recorded here.** This file starts at the release
> that introduced it; earlier versions are described by their git tags.

## [Unreleased]

### Changed

- Rebranded the display name to Fancy Dev Kit (README and package description). Package names, the npm scope, URLs and the `fancy-ui` MCP id are unchanged.
- **Docs:** `AGENTS.md` added: the guide to using this package (install, its entries on the Fancy MCP catalog server, API essentials, traps). `CONTRIBUTING.md` added for the contributor notes, with `CLAUDE.md` as a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.
- CI: re-pin org third-party allowlist to 2614224

## [0.4.1] - 2026-11-03

### Fixed

- **`GitRepository::log()` and `branches()` threw a `TypeError` on every non-empty
  result, on every platform, since the first release.** git ends each record with
  the separator and then a newline, and that leftover newline was parsed as a row.
  Records now split on the separator and its newline, and nothing else is trimmed,
  so a subject keeps its own trailing characters. CRLF output and a final record
  without its newline are accepted. Output that is not a record is now a
  `GitException` rather than a `TypeError`. The `git-log` and `git-branches` flow
  nodes call these methods and failed the same way, so they work once this version
  is installed.

  **What you must do:** nothing. These calls could not succeed on a non-empty
  result before, so no working code depends on the old behaviour.

- **Text in a commit can no longer forge fields or commits in `log()`.** An author
  name or a subject can contain the separators `log()` used, so a commit could shift
  its own fields or add a row with an id of its choosing. Fields are now separated
  by NUL, which a commit cannot contain.

  **What you must do:** nothing, unless you assert on the exact command through a
  custom `ProcessRunner`, in which case the `--format` argument has changed.

- **`status()` lost a file when a file named `0` was renamed.** The `0` was filtered
  out as if it were empty, so the next record was taken as the previous path and
  that file vanished from the result.

  **What you must do:** nothing.

## [0.4.0] - 2026-11-03

### Changed

- **The tag workflow is now `.github/workflows/publish.yml`, named `Publish`**
  (it was `release-gate.yml`, "Release gate"). Every Particle-Academy package
  publishes from that file under that name. What it does is unchanged: on a
  `v*` tag it checks that `CHANGELOG.md` has an entry for the version and that
  the entry is the newest one. Packagist syncs from the tag and never reads the
  workflow file, so nothing about how a release reaches Composer changes.

  **What you must do:** nothing. Only a script that looks runs up by the old
  file (`gh run list --workflow=release-gate.yml`) needs `publish.yml` instead.

### Security

- **`SymfonyProcessRunner` now runs `git` by absolute path, and on Windows no
  longer goes through `cmd.exe`.** Which program ran could depend on the current
  directory, so a repository could decide what ran when a process was started
  inside it. The runner now finds `git` on `PATH` once per process, reading only
  absolute entries, and never the current directory or the repository. If it finds
  none it fails with a `GitException` (`NotFound`) rather than falling back.

  On Windows it now starts the program directly with `proc_open` instead of through
  Symfony Process, because Symfony's own `cmd.exe` and `taskkill` lookups had the
  same exposure. POSIX still uses Symfony Process.

  **What you must do:** nothing, unless one of these applies:
  - The only `git` you have is in the current directory or on a relative `PATH`
    entry. Add it to `PATH` by absolute path, or pass a `ProcessRunner` that calls
    your own copy.
  - On Windows, your first `git` on `PATH` is a `.cmd` or `.bat` wrapper. It is no
    longer run. A real `git.exe` later on `PATH` is used instead; with none, you
    get a `NotFound` error that names the wrapper.
  - You run under `open_basedir` and `git` is outside it. POSIX keeps working when
    every `PATH` entry is absolute. Otherwise you get a `NotFound` error, and need
    a `ProcessRunner` that calls `git` by absolute path.

  A custom `ProcessRunner` keeps working unchanged: it still receives the name
  `git`.

## [0.3.1] — 2026-09-13

### Fixed

- **Error-message redaction no longer blanks ordinary provider text.** It removed
  the word after any "token", "password" or "Bearer", so GitLab's
  "Token scope insufficient" reached you as "[REDACTED] insufficient", and
  "use a token instead of a password" lost half its words. Redaction now matches
  credentials by their shape or their context instead.

  **What you must do:** nothing, unless you matched on the old output. A redacted
  header now keeps its name and scheme — `Authorization: Bearer [REDACTED]`
  rather than `Authorization: [REDACTED]` — so a test asserting the old string
  needs the new one.

### Security

- **Redaction now catches secrets it used to let through:** a `PRIVATE-TOKEN:`,
  `JOB-TOKEN:` or `DEPLOY-TOKEN:` header whose value has no `glpat-` prefix,
  `Authorization: Basic …`, credentials in a query string or assignment
  (`private_token=`, `access_token=`, `password=`, `client_secret=`), GitHub
  fine-grained PATs (`github_pat_…`), and GitLab's other token kinds (`glcbt-`,
  `gldt-`, `gloas-`, `glrt-`, `glptt-`, `glft-` and the rest). Classic GitHub
  tokens, `glpat-`, bearer values and URL userinfo are still caught.

  **What you must do:** nothing. The cases are pinned in
  `tests/fixtures/redaction-cases.json`, shared byte-for-byte with
  `@particle-academy/fancy-git` 0.3.1 so the PHP and Node runtimes redact
  identically.

## [0.3.0] — 2026-08-07

### Changed

- **BREAKING — PHP 8.3 is no longer supported.** `require.php` moves from `^8.3` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.3, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


## [0.2.0] - 2026-07-31

### Added

- **`Provider\IssueProvider` — issue tracking, as an OPTIONAL capability.**
  `listIssues`, `getIssue`, `createIssue`, `updateIssue`, `commentOnIssue`, with
  a normalized issue shape (id, number, title, state, webUrl, author, labels,
  assignees, timestamps).

  **No action required, and nothing breaks.** It is deliberately a *separate*
  interface rather than five methods added to `GitProvider`: that interface is
  implemented by every provider, including ones outside this package, and adding
  to it would break each of them at load time for a capability many hosts do not
  offer. A self-hosted remote with no tracker is a perfectly good `GitProvider`.

  An adapter opts in, and a caller asks before reaching for it:

  ```php
  if ($provider instanceof IssueProvider) {
      $provider->createIssue($ref, ['title' => 'Broken']);
  }
  ```

  The normalized shape is thinner than any one host's model on purpose. GitHub
  has milestones and state reasons, GitLab has weights and epics, Bitbucket has
  kinds and priorities — none of which survive a move between hosts. What they
  all agree on is normalized; the rest belongs in `extensions`, where a consumer
  that knows its host can reach it without the contract pretending it is
  portable.

  Implemented by `particle-academy/fancy-git-github` 0.2.0. The GitLab and
  Bitbucket adapters do not implement it yet, and `instanceof` reports that
  honestly rather than throwing at call time.

[0.2.0]: https://github.com/Particle-Academy/fancy-git-php/releases/tag/v0.2.0

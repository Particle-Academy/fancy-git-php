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

- **The tag workflow is now `.github/workflows/publish.yml`, named `Publish`**
  (it was `release-gate.yml`, "Release gate"). Every Particle-Academy package
  publishes from that file under that name. What it does is unchanged: on a
  `v*` tag it checks that `CHANGELOG.md` has an entry for the version and that
  the entry is the newest one. Packagist syncs from the tag and never reads the
  workflow file, so nothing about how a release reaches Composer changes.

  **What you must do:** nothing. Only a script that looks runs up by the old
  file (`gh run list --workflow=release-gate.yml`) needs `publish.yml` instead.

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

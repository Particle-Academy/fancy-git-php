# Changelog

All notable changes to `particle-academy/fancy-git` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0: breaking changes land in MINOR releases.** Read the entry, not the
> version number.

> **History before 0.2.0 is not recorded here.** This file starts at the release
> that introduced it; earlier versions are described by their git tags.

## [Unreleased]

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

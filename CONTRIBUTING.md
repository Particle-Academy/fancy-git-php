# Contributing to particle-academy/fancy-git

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Layout

- `src/GitRepository.php` — local Git operations (namespace `FancyGit`).
- `src/Process/` — `ProcessRunner`, `SymfonyProcessRunner`,
  `ExecutableResolver` (how `git` is found).
- `src/Provider/` — the `GitProvider` / `IssueProvider` contracts and
  `ProviderRegistry`.
- `src/Model/`, `src/Error/` — value objects, `GitException`, `GitErrorCode`.
- `tests/` — the Pest suite; `tests/StubProcessRunner.php` is the test
  double, fixtures in `tests/fixtures/`.

## Invariants

- Refs and remotes can come from an untrusted caller. A value git would parse as
  an option (leading `-`), or a remote using a command-executing transport
  helper, is refused with `InvalidArgument` before it reaches git.
- POSIX runs go through Symfony Process; on Windows the program is started
  directly with `proc_open`, so no `cmd.exe` is involved.
- The core has no GitHub, GitLab or Bitbucket client; those are separate adapter
  packages.

## Commands

```bash
composer install
composer test   # pest
```

## Changelog

Update `CHANGELOG.md` (Keep a Changelog) in the same commit as the change.

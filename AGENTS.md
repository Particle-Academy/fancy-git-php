# Using particle-academy/fancy-git — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-git
```

- Requires PHP `^8.4` and `symfony/process` (`^7.2|^8.0`). It runs the system
  `git`.
- Hosted-provider access is in separate adapters:
  `particle-academy/fancy-git-github`, `particle-academy/fancy-git-gitlab`,
  `particle-academy/fancy-git-bitbucket`.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package is the registry entry `fancy-git`; the
adapters are `fancy-git-github-php`, `fancy-git-gitlab-php` and
`fancy-git-bitbucket-php`. Server-side packages are found with
`search-backend-packages` (stack `php`); `install-instructions` gives the exact
commands for a slug.

## API essentials

```php
use FancyGit\GitRepository;
use FancyGit\Provider\ProviderRegistry;

$repository = new GitRepository(getcwd());
$status = $repository->status();

// An agent proposes; nothing changes until it is applied.
$proposal = $repository->stage(['README.md'], propose: true);
```

- `GitRepository` reads: `info`, `status`, `log`, `diff`, `branches`. Mutations:
  `stage`, `unstage`, `checkout`, `fetch`, `pull`, `push`. Every call takes an
  optional `$timeout` (seconds).
- Mutations take `propose: true` and then return an `OperationProposal`
  (`operation`, `arguments`, `summary`; `JsonSerializable`) instead of changing
  the repository. Applied, they return `null`.
- `ProviderRegistry` holds `GitProvider` adapters: `register($provider)`,
  `get($kind)`, and `identify($remote)`, which finds the adapter that
  recognises the remote.
- Failures are `FancyGit\Error\GitException` with a `GitErrorCode` (`Auth`,
  `Conflict`, `DirtyWorktree`, `NotFound`, `NonFastForward`, `RateLimited`,
  `Cancelled`, `Unsupported`, `InvalidArgument`, `Unknown`).

## Traps

- **Which `git` runs.** The core runs the `git` it finds on an absolute `PATH`
  entry. The current directory and the repository are never searched, and a
  batch-file `git` is not run; if nothing else is on `PATH` you get a
  `GitException` with code `NotFound`. To use a particular git, pass your own
  `ProcessRunner` to `GitRepository`, or call `SymfonyProcessRunner::run()` with
  an absolute path.
- **Option-shaped input is refused.** A ref or remote starting with `-`, or a
  remote using a command-executing transport helper, throws `InvalidArgument`
  rather than reaching git.
- The PHP core has no `commit` method; the Node package
  (`@particle-academy/fancy-git`) does.

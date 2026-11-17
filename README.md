# Fancy Git for PHP

[![Fancified](art/fancified.svg)](https://particle.academy)

Framework-agnostic local Git operations and normalized provider contracts.

```php
use FancyGit\GitRepository;

$repository = new GitRepository(getcwd());
$status = $repository->status();
```

The core runs the `git` it finds on an absolute `PATH` entry, once per process.
The current directory and the repository are never searched, and a batch-file
`git` is not run: if nothing else is on `PATH` you get a `GitException` with code
`NotFound`. It uses Symfony Process on POSIX and starts the program directly with
`proc_open` on Windows, so no `cmd.exe` is involved. To use a particular git, give
`GitRepository` your own `ProcessRunner`, or call `SymfonyProcessRunner::run()`
with an absolute path. GitHub, GitLab, and Bitbucket clients live in separate
adapter packages.

<!-- fancy-agents-note:start -->
> **For agents.** Every Fancy package, this one included, is listed on the
> Fancy MCP catalog server at `https://ui.particle.academy/mcp` (streamable HTTP, no API key;
> MCP id `fancy-ui`), with install commands and docs. How an agent installs
> and uses this package is in [AGENTS.md](https://github.com/Particle-Academy/fancy-git-php/blob/main/AGENTS.md).
<!-- fancy-agents-note:end -->

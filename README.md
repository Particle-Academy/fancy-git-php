# Fancy Git for PHP

[![Fancified](art/fancified.svg)](https://particle.academy)

Framework-agnostic local Git operations and normalized provider contracts.

```php
use FancyGit\GitRepository;

$repository = new GitRepository(getcwd());
$status = $repository->status();
```

The core uses Symfony Process for safe, portable invocation of the installed Git
binary. GitHub, GitLab, and Bitbucket clients live in separate adapter packages.

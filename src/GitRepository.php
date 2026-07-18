<?php

declare(strict_types=1);

namespace FancyGit;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\Model\Commit;
use FancyGit\Model\FileChange;
use FancyGit\Model\OperationProposal;
use FancyGit\Model\RepositoryInfo;
use FancyGit\Model\WorkingTreeStatus;
use FancyGit\Process\ProcessRunner;
use FancyGit\Process\SymfonyProcessRunner;

final class GitRepository
{
    private const FIELD = "\x1f";
    private const RECORD = "\x1e";

    public readonly string $directory;

    public function __construct(string $directory, private ?ProcessRunner $runner = null)
    {
        $this->directory = realpath($directory) ?: $directory;
        $this->runner ??= new SymfonyProcessRunner;
    }

    public function info(?float $timeout = null): RepositoryInfo
    {
        $root = trim($this->git(['rev-parse', '--show-toplevel'], $timeout));
        $bare = trim($this->git(['rev-parse', '--is-bare-repository'], $timeout)) === 'true';
        $branch = trim($this->git(['branch', '--show-current'], $timeout)) ?: null;
        try {
            $head = trim($this->git(['rev-parse', 'HEAD'], $timeout));
        } catch (\Throwable) {
            $head = null;
        }

        return new RepositoryInfo($root, $branch, $head, $bare);
    }

    public function status(?float $timeout = null): WorkingTreeStatus
    {
        $records = array_values(array_filter(explode("\0", $this->git(['status', '--porcelain=v2', '--branch', '-z'], $timeout))));
        $branch = $upstream = null;
        $ahead = $behind = 0;
        $files = [];

        for ($i = 0, $count = count($records); $i < $count; $i++) {
            $record = $records[$i];
            if (str_starts_with($record, '# branch.head ')) {
                $value = substr($record, 14);
                $branch = $value === '(detached)' ? null : $value;
            } elseif (str_starts_with($record, '# branch.upstream ')) {
                $upstream = substr($record, 18);
            } elseif (str_starts_with($record, '# branch.ab ') && preg_match('/\+(\d+) -(\d+)/', $record, $match)) {
                $ahead = (int) $match[1];
                $behind = (int) $match[2];
            } elseif (str_starts_with($record, '? ')) {
                $files[] = new FileChange(substr($record, 2), null, 'untracked');
            } elseif (str_starts_with($record, '1 ') || str_starts_with($record, '2 ')) {
                $parts = explode(' ', $record);
                $xy = $parts[1];
                $offset = str_starts_with($record, '2 ') ? 9 : 8;
                $path = implode(' ', array_slice($parts, $offset));
                $previous = str_starts_with($record, '2 ') ? ($records[++$i] ?? null) : null;
                $files[] = new FileChange($path, $this->change($xy[0]), $this->change($xy[1]), $previous);
            } elseif (str_starts_with($record, 'u ')) {
                $files[] = new FileChange(implode(' ', array_slice(explode(' ', $record), 10)), 'conflicted', 'conflicted');
            }
        }

        return new WorkingTreeStatus($branch, $upstream, $ahead, $behind, $files, $files === []);
    }

    /** @return list<Commit> */
    public function log(?string $ref = null, int $limit = 50, int $skip = 0, ?float $timeout = null): array
    {
        $format = implode(self::FIELD, ['%H', '%h', '%P', '%an', '%ae', '%aI', '%s']).self::RECORD;
        $args = ['log', "--format={$format}", "--max-count={$limit}", "--skip={$skip}"];
        if ($ref !== null) {
            self::assertNotOption($ref, 'log ref');
            $args[] = $ref;
        }

        $rows = array_filter(explode(self::RECORD, $this->git($args, $timeout)), 'strlen');

        return array_values(array_map(static function (string $row): Commit {
            [$id, $shortId, $parents, $authorName, $authorEmail, $authoredAt, $subject] = explode(self::FIELD, trim($row));

            return new Commit($id, $shortId, $parents === '' ? [] : explode(' ', $parents), $authorName, $authorEmail, $authoredAt, $subject);
        }, $rows));
    }

    /** @param list<string> $paths */
    public function stage(array $paths, bool $propose = false, ?float $timeout = null): ?OperationProposal
    {
        if ($propose) {
            return new OperationProposal('stage', ['paths' => $paths], sprintf('Stage %d path(s)', count($paths)));
        }
        $this->git(['add', '--', ...$paths], $timeout);

        return null;
    }

    /** @param list<string> $paths */
    public function unstage(array $paths, bool $propose = false, ?float $timeout = null): ?OperationProposal
    {
        if ($propose) {
            return new OperationProposal('unstage', ['paths' => $paths], sprintf('Unstage %d path(s)', count($paths)));
        }
        $this->git(['restore', '--staged', '--', ...$paths], $timeout);

        return null;
    }

    /** @return array{patch:string,files:list<string>} */
    public function diff(?string $from = null, ?string $to = null, bool $staged = false, array $paths = [], ?float $timeout = null): array
    {
        $args = ['diff', '--no-ext-diff', '--binary'];
        if ($staged) {
            $args[] = '--cached';
        }
        foreach ([$from, $to] as $ref) {
            if ($ref !== null) {
                self::assertNotOption($ref, 'diff ref');
                $args[] = $ref;
            }
        }
        if ($paths !== []) {
            array_push($args, '--', ...$paths);
        }
        $patch = $this->git($args, $timeout);
        preg_match_all('/^diff --git a\/(.+?) b\/(.+)$/m', $patch, $matches);

        return ['patch' => $patch, 'files' => $matches[2] ?? []];
    }

    /** @return list<array{name:string,current:bool,remote:bool,target:string,upstream?:string}> */
    public function branches(?float $timeout = null): array
    {
        $format = implode(self::FIELD, ['%(refname:short)', '%(HEAD)', '%(objectname)', '%(upstream:short)', '%(refname)']).self::RECORD;
        $rows = array_filter(explode(self::RECORD, $this->git(['for-each-ref', "--format={$format}", 'refs/heads', 'refs/remotes'], $timeout)), 'strlen');

        return array_values(array_map(static function (string $row): array {
            [$name, $head, $target, $upstream, $refname] = explode(self::FIELD, trim($row));
            $branch = ['name' => $name, 'current' => $head === '*', 'remote' => str_starts_with($refname, 'refs/remotes/'), 'target' => $target];
            if ($upstream !== '') {
                $branch['upstream'] = $upstream;
            }

            return $branch;
        }, $rows));
    }

    public function checkout(string $target, bool $propose = false, ?float $timeout = null): ?OperationProposal
    {
        self::assertNotOption($target, 'checkout target');
        if ($propose) {
            return new OperationProposal('checkout', ['target' => $target], "Check out {$target}");
        }
        $this->git(['checkout', $target], $timeout);

        return null;
    }

    public function fetch(string $remote = 'origin', bool $propose = false, ?float $timeout = null): ?OperationProposal
    {
        self::assertSafeRemote($remote);
        if ($propose) {
            return new OperationProposal('fetch', ['remote' => $remote], "Fetch {$remote}");
        }
        $this->git(['fetch', '--progress', $remote], $timeout);

        return null;
    }

    public function pull(?string $remote = null, ?string $branch = null, bool $propose = false, ?float $timeout = null): ?OperationProposal
    {
        if ($remote !== null) {
            self::assertSafeRemote($remote);
        }
        if ($branch !== null) {
            self::assertNotOption($branch, 'branch');
        }
        if ($propose) {
            return new OperationProposal('pull', ['remote' => $remote, 'branch' => $branch], 'Pull remote changes');
        }
        $args = ['pull', '--ff-only'];
        foreach ([$remote, $branch] as $value) {
            if ($value !== null) {
                $args[] = $value;
            }
        }
        $this->git($args, $timeout);

        return null;
    }

    public function push(string $remote = 'origin', ?string $branch = null, bool $propose = false, ?float $timeout = null): ?OperationProposal
    {
        self::assertSafeRemote($remote);
        if ($branch !== null) {
            self::assertNotOption($branch, 'branch');
        }
        if ($propose) {
            return new OperationProposal('push', ['remote' => $remote, 'branch' => $branch], "Push to {$remote}");
        }
        $args = ['push', '--progress', $remote];
        if ($branch !== null) {
            $args[] = $branch;
        }
        $this->git($args, $timeout);

        return null;
    }

    private function git(array $args, ?float $timeout): string
    {
        // Defense in depth: disable the `ext::` remote helper for every
        // invocation so a transport helper can never execute a command even if
        // a future call path forgets to validate its remote.
        return $this->runner->run(['git', '-c', 'protocol.ext.allow=never', '-C', $this->directory, '--no-pager', ...$args], $timeout)->stdout;
    }

    /**
     * These packages are agent-driven: refs and remotes can be supplied by an
     * untrusted caller, so a value git would parse as an option (e.g.
     * `--output=/path`, `--upload-pack=<cmd>`) must never reach it as a
     * positional argument.
     */
    private static function assertNotOption(string $value, string $label): void
    {
        if (str_starts_with($value, '-')) {
            throw new GitException(GitErrorCode::InvalidArgument, "Refusing {$label} that resembles a command-line option: {$value}");
        }
    }

    /**
     * Reject a remote using a git remote-helper transport that can execute a
     * local command (`ext::sh -c …`, `fd::…`) — the classic git-wrapper RCE —
     * on top of the option check.
     */
    private static function assertSafeRemote(string $remote, string $label = 'remote'): void
    {
        self::assertNotOption($remote, $label);
        if (preg_match('/^(ext|fd)::/i', $remote) === 1) {
            throw new GitException(GitErrorCode::InvalidArgument, "Refusing {$label} using a disallowed transport helper: {$remote}");
        }
    }

    private function change(string $code): ?string
    {
        return match ($code) {
            '.' => null,
            'A' => 'added',
            'M' => 'modified',
            'D' => 'deleted',
            'R' => 'renamed',
            'C' => 'copied',
            'U' => 'conflicted',
            '?' => 'untracked',
            default => 'conflicted',
        };
    }
}

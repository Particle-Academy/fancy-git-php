<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\GitRepository;
use PHPUnit\Framework\TestCase;

final class GitRepositoryTest extends TestCase
{
    public function test_it_parses_porcelain_v2_status(): void
    {
        $runner = new StubProcessRunner([
            "# branch.head main\0# branch.upstream origin/main\0# branch.ab +2 -1\0".
            "1 M. N... 100644 100644 100644 a b README.md\0? new file.txt\0",
        ]);

        $status = (new GitRepository('.', $runner))->status();

        self::assertSame('main', $status->branch);
        self::assertSame(2, $status->ahead);
        self::assertSame('modified', $status->files[0]->index);
        self::assertNull($status->files[0]->worktree);
        self::assertSame('untracked', $status->files[1]->worktree);
    }

    public function test_proposal_mode_does_not_execute_git(): void
    {
        $runner = new StubProcessRunner;
        $proposal = (new GitRepository('.', $runner))->stage(['a.txt'], true);

        self::assertSame('stage', $proposal?->operation);
        self::assertSame([], $runner->calls);
    }
}

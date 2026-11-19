<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\Error\GitException;
use FancyGit\GitRepository;

test('it rejects ext transport remotes without invoking git', function () {
    $runner = new StubProcessRunner;
    $repo = new GitRepository('.', $runner);

    foreach (['fetch', 'pull', 'push'] as $method) {
        try {
            $repo->{$method}('ext::sh -c id');
            self::fail("{$method} should reject an ext:: remote");
        } catch (GitException $e) {
            self::assertStringContainsString('transport helper', $e->getMessage());
        }
    }
    self::assertSame([], $runner->calls);
});

test('it rejects option like refs and remotes without invoking git', function () {
    $runner = new StubProcessRunner;
    $repo = new GitRepository('.', $runner);

    $cases = [
        fn () => $repo->fetch('--upload-pack=touch pwned'),
        fn () => $repo->push('--receive-pack=x'),
        fn () => $repo->log('--output=/tmp/x'),
        fn () => $repo->diff('--output=/tmp/x'),
        fn () => $repo->checkout('--orphan'),
    ];
    foreach ($cases as $case) {
        try {
            $case();
            self::fail('expected a GitException for an option-like value');
        } catch (GitException $e) {
            self::assertStringContainsString('command-line option', $e->getMessage());
        }
    }
    self::assertSame([], $runner->calls);
});

test('it hardens git against the ext transport on a normal remote', function () {
    $runner = new StubProcessRunner(['']);
    (new GitRepository('.', $runner))->fetch('origin');

    $call = $runner->calls[0];
    self::assertSame('git', $call[0]);
    self::assertSame('-c', $call[1]);
    self::assertSame('protocol.ext.allow=never', $call[2]);
    self::assertContains('fetch', $call);
    self::assertContains('origin', $call);
});

test('it parses porcelain v2 status', function () {
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
});

test('proposal mode does not execute git', function () {
    $runner = new StubProcessRunner;
    $proposal = (new GitRepository('.', $runner))->stage(['a.txt'], true);

    self::assertSame('stage', $proposal?->operation);
    self::assertSame([], $runner->calls);
});

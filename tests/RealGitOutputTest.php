<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\Error\GitException;
use FancyGit\GitRepository;
use PHPUnit\Framework\TestCase;

/**
 * Every public read method against what git really prints, in a real repository.
 *
 * The gap this closes: log() and branches() threw a TypeError on ANY non-empty
 * result from the day they were written, and nothing noticed, because the only
 * tests of them used a rejected ref or a stub that never returned real output.
 * A stub only proves a parser agrees with the stub's author about git's format.
 *
 * Expected values come from the commit() helper or a separate git call, never
 * from the parser. Commands run with no global or system config, so a developer's
 * core.quotePath or format settings cannot decide what is being tested.
 */
abstract class RealGitOutputCase extends TestCase
{
    /** Not UTC: git versions differ on whether %aI spells that "Z" or "+00:00". */
    protected const DATE = '2026-01-02T03:04:05+02:00';

    protected string $root;

    /** @var array<string, mixed> */
    protected array $savedEnv;

    // -------------------------------------------------------------- helpers

    /** @param list<\FancyGit\Model\Commit> $log */
    protected function subjects(array $log): array
    {
        return array_map(static fn ($commit) => $commit->subject, $log);
    }

    /** @return list<array<string, mixed>> */
    protected function files(\FancyGit\Model\WorkingTreeStatus $status): array
    {
        return array_map(static fn ($file) => [
            'path' => $file->path,
            'index' => $file->index,
            'worktree' => $file->worktree,
            ...($file->previousPath === null ? [] : ['previousPath' => $file->previousPath]),
        ], $status->files);
    }

    protected function repo(string $name): string
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.$name;
        mkdir($dir);
        $this->git($dir, ['init', '-q', '-b', 'main']);

        return $dir;
    }

    protected function clone(string $from, string $name): string
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.$name;
        $this->git($this->root, ['clone', '-q', $from, $dir]);

        return $dir;
    }

    protected function write(string $repo, string $path, string $content): void
    {
        file_put_contents($repo.DIRECTORY_SEPARATOR.$path, $content);
    }

    /** @return string the new commit's full hash, read back from git */
    protected function commit(string $repo, string $message, string $authorName = 'Ada Lovelace', string $authorEmail = 'ada@example.com'): string
    {
        $this->git($repo, [
            '-c', "user.name={$authorName}",
            '-c', "user.email={$authorEmail}",
            'commit', '-q', '--allow-empty', '--allow-empty-message', '--cleanup=verbatim', '-m', $message,
        ]);

        return trim($this->git($repo, ['rev-parse', 'HEAD']));
    }

    /** Deliberately not the code under test: this is the test's own route to git. */
    protected function git(string $cwd, array $args, bool $mayFail = false): string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $env = [
            'GIT_CONFIG_GLOBAL' => $_ENV['GIT_CONFIG_GLOBAL'],
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_DATE' => self::DATE,
            'GIT_COMMITTER_DATE' => self::DATE,
        ] + getenv();

        $process = proc_open(['git', '-C', $cwd, ...$args], [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 && ! $mayFail) {
            self::fail('git '.implode(' ', $args)." exited {$code}: {$stderr}");
        }

        return $stdout;
    }

    /** git writes its objects read-only, which stops unlink() on Windows. */
    protected function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path.DIRECTORY_SEPARATOR.$entry);
                }
            }
            rmdir($path);

            return;
        }
        chmod($path, 0666);
        unlink($path);
    }
}

uses(RealGitOutputCase::class);

beforeEach(function () {
    $this->root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).DIRECTORY_SEPARATOR.'fancy-git-real-'.bin2hex(random_bytes(6));
    mkdir($this->root);

    // Both process runners read $_ENV on top of the inherited environment.
    $this->savedEnv = $_ENV;
    $_ENV['GIT_CONFIG_GLOBAL'] = $this->root.DIRECTORY_SEPARATOR.'no-such-gitconfig';
    $_ENV['GIT_CONFIG_NOSYSTEM'] = '1';
});

afterEach(function () {
    $_ENV = $this->savedEnv;
    $this->remove($this->root);
});

// ---------------------------------------------------------------- log()

test('log parses every field of every record', function () {
    $repo = $this->repo('app');
    $first = $this->commit($repo, 'first commit');
    $second = $this->commit($repo, 'second commit', 'Grace Hopper', 'grace@example.com');

    $log = (new GitRepository($repo))->log();

    self::assertCount(2, $log);
    self::assertStringStartsWith($log[0]->shortId, $second);
    self::assertGreaterThanOrEqual(7, strlen($log[0]->shortId));
    self::assertSame([
        'id' => $second,
        'shortId' => $log[0]->shortId,
        'parents' => [$first],
        'authorName' => 'Grace Hopper',
        'authorEmail' => 'grace@example.com',
        'authoredAt' => self::DATE,
        'subject' => 'second commit',
    ], $log[0]->jsonSerialize());
    self::assertSame([
        'id' => $first,
        'shortId' => $log[1]->shortId,
        'parents' => [],
        'authorName' => 'Ada Lovelace',
        'authorEmail' => 'ada@example.com',
        'authoredAt' => self::DATE,
        'subject' => 'first commit',
    ], $log[1]->jsonSerialize());
});

test('log returns both parents of a merge', function () {
    $repo = $this->repo('app');
    $this->commit($repo, 'base');
    $this->git($repo, ['checkout', '-q', '-b', 'side']);
    $side = $this->commit($repo, 'on side');
    $this->git($repo, ['checkout', '-q', 'main']);
    $main = $this->commit($repo, 'on main');
    $this->git($repo, ['-c', 'user.name=Ada', '-c', 'user.email=ada@example.com', 'merge', '-q', '--no-ff', '-m', 'merge side', 'side']);

    $log = (new GitRepository($repo))->log(limit: 1);

    self::assertSame([$main, $side], $log[0]->parents);
    self::assertSame('merge side', $log[0]->subject);
});

test('log honours ref limit and skip', function () {
    $repo = $this->repo('app');
    $this->commit($repo, 'one');
    $this->commit($repo, 'two');
    $this->commit($repo, 'three');
    $this->git($repo, ['branch', 'old', 'HEAD~1']);
    $git = new GitRepository($repo);

    self::assertSame(['two', 'one'], $this->subjects($git->log('old')));
    self::assertSame(['three'], $this->subjects($git->log(limit: 1)));
    self::assertSame(['two'], $this->subjects($git->log(limit: 1, skip: 1)));
});

test('log is empty when real git prints nothing', function () {
    $repo = $this->repo('app');
    $this->commit($repo, 'only');
    $git = new GitRepository($repo);

    self::assertSame([], $git->log(skip: 5));
    self::assertSame([], $git->log(limit: 0));
});

/**
 * The characters a blanket trim() eats, the ones a parser might split on, and
 * the ones a shell or a format string would mangle. Each commit is committed
 * verbatim so git's own message cleanup is not what is being tested.
 */
test('log keeps a subject exactly as git reports it', function () {
    $subjects = [
        'plain',
        'quotes " and \' and `ticks`',
        'back\\slash, 100% %H %x1e | pipe; $HOME',
        "caf\xc3\xa9 \xe2\x9c\x93 unicode",
        "tab\tinside",
        '  leading spaces',
        "ends with a vertical tab\x0B",
        "ends with a non-breaking space\xc2\xa0",
        "field\x1fseparator\x1finside",
        "ends with the field separator\x1f",
        "record\x1eseparator\x1einside",
        "ends with the record separator\x1e",
        '0',
    ];
    $repo = $this->repo('app');
    foreach ($subjects as $subject) {
        $this->commit($repo, $subject);
    }

    $log = (new GitRepository($repo))->log();

    self::assertSame(array_reverse($subjects), $this->subjects($log));
});

test('log folds a multi line subject and drops the body', function () {
    $repo = $this->repo('app');
    $this->commit($repo, 'before');
    $this->commit($repo, "line one\nline two\n\nthe body\x1e\nmore body");
    $this->commit($repo, 'after');

    $log = (new GitRepository($repo))->log();

    self::assertSame(['after', 'line one line two', 'before'], $this->subjects($log));
});

test('log reads an empty commit message as an empty subject', function () {
    $repo = $this->repo('app');
    $id = $this->commit($repo, '');

    $log = (new GitRepository($repo))->log();

    self::assertCount(1, $log);
    self::assertSame($id, $log[0]->id);
    self::assertSame('', $log[0]->subject);
});

/** A repository's history is untrusted: its text must not be able to forge a field or a commit. */
test('log text cannot forge fields or records', function () {
    $forged = str_repeat('f', 40);
    $repo = $this->repo('app');
    $base = $this->commit($repo, 'base');
    $evil = $this->commit(
        $repo,
        "looks fine\x1e{$forged}\x1ffff\x1f\x1fForged\x1fforged@example.com\x1f2020-01-01T00:00:00+00:00\x1fforged commit",
    );
    $named = $this->commit($repo, 'named', "Eve\x1fspoof@example.com\x1f2020-01-01T00:00:00+00:00\x1fforged subject");

    $log = (new GitRepository($repo))->log();

    self::assertSame([$named, $evil, $base], array_map(static fn ($commit) => $commit->id, $log));
    self::assertSame('named', $log[0]->subject);
    self::assertSame('ada@example.com', $log[0]->authorEmail);
    self::assertSame(self::DATE, $log[0]->authoredAt);
    self::assertSame("Eve\x1fspoof@example.com\x1f2020-01-01T00:00:00+00:00\x1fforged subject", $log[0]->authorName);
    self::assertSame('Ada Lovelace', $log[1]->authorName);
});

/**
 * Pinned, not endorsed: an unborn HEAD is a typed failure from git itself, the
 * same as fancy-git-js. What must never happen here is the TypeError.
 */
test('log on a repository with no commits is a typed failure', function () {
    $repo = $this->repo('empty');

    try {
        (new GitRepository($repo))->log();
        self::fail('git refuses to log an unborn HEAD');
    } catch (GitException $e) {
        self::assertNotSame(0, $e->getCode());
    }
});

// ----------------------------------------------------------- branches()

test('branches parses local branches', function () {
    $repo = $this->repo('app');
    $first = $this->commit($repo, 'first');
    $second = $this->commit($repo, 'second');
    $this->git($repo, ['branch', 'feature/nested-name', $first]);

    $branches = (new GitRepository($repo))->branches();

    self::assertSame([
        ['name' => 'feature/nested-name', 'current' => false, 'remote' => false, 'target' => $first],
        ['name' => 'main', 'current' => true, 'remote' => false, 'target' => $second],
    ], $branches);
});

test('branches reports upstream and remote refs', function () {
    $origin = $this->repo('origin');
    $head = $this->commit($origin, 'first');
    $this->git($origin, ['branch', 'other']);
    $clone = $this->clone($origin, 'clone');
    $this->git($clone, ['branch', 'solo']);

    $branches = [];
    foreach ((new GitRepository($clone))->branches() as $branch) {
        $branches[$branch['name']] = $branch;
    }

    self::assertSame(['name' => 'main', 'current' => true, 'remote' => false, 'target' => $head, 'upstream' => 'origin/main'], $branches['main']);
    self::assertSame(['name' => 'solo', 'current' => false, 'remote' => false, 'target' => $head], $branches['solo']);
    self::assertSame(['name' => 'origin/main', 'current' => false, 'remote' => true, 'target' => $head], $branches['origin/main']);
    self::assertSame(['name' => 'origin/other', 'current' => false, 'remote' => true, 'target' => $head], $branches['origin/other']);
});

test('branches marks none current on a detached head', function () {
    $repo = $this->repo('app');
    $this->commit($repo, 'first');
    $this->git($repo, ['checkout', '-q', '--detach']);

    $branches = (new GitRepository($repo))->branches();

    self::assertSame(['main'], array_column($branches, 'name'));
    self::assertFalse($branches[0]['current']);
});

test('branches is empty on a repository with no commits', function () {
    self::assertSame([], (new GitRepository($this->repo('empty')))->branches());
});

// ------------------------------------------- what the record split must survive

test('records split on crlf as well as lf', function () {
    $log = "a\x00b\x00\x00n\x00e\x00d\x00one\x1e\r\nc\x00d\x00a\x00n\x00e\x00d\x00two\x1e\r\n";
    $refs = "main\x1f*\x1fabc\x1f\x1frefs/heads/main\x1e\r\nold\x1f \x1fdef\x1f\x1frefs/heads/old\x1e\r\n";

    self::assertSame(['one', 'two'], $this->subjects((new GitRepository('.', new StubProcessRunner([$log])))->log()));
    self::assertSame(['main', 'old'], array_column((new GitRepository('.', new StubProcessRunner([$refs])))->branches(), 'name'));
});

test('a final record may arrive without its newline', function () {
    $log = "a\x00b\x00\x00n\x00e\x00d\x00only\x1e";

    self::assertSame(['only'], $this->subjects((new GitRepository('.', new StubProcessRunner([$log])))->log()));
});

test('output that is not a record is a typed failure', function () {
    foreach ([fn ($git) => $git->log(), fn ($git) => $git->branches()] as $call) {
        try {
            $call(new GitRepository('.', new StubProcessRunner(["\n"])));
            self::fail('a stray newline is not a record');
        } catch (GitException $e) {
            self::assertStringContainsString('Unexpected', $e->getMessage());
        }
    }
});

// ------------------------------------------------------------- status()

test('status of a clean repository', function () {
    $repo = $this->repo('app');
    $this->commit($repo, 'first');

    $status = (new GitRepository($repo))->status();

    self::assertSame('main', $status->branch);
    self::assertNull($status->upstream);
    self::assertSame(0, $status->ahead);
    self::assertSame(0, $status->behind);
    self::assertSame([], $status->files);
    self::assertTrue($status->clean);
});

test('status of a repository with no commits', function () {
    $repo = $this->repo('empty');
    $this->write($repo, 'new.txt', "x\n");

    $status = (new GitRepository($repo))->status();

    self::assertSame('main', $status->branch);
    self::assertFalse($status->clean);
    self::assertSame([['path' => 'new.txt', 'index' => null, 'worktree' => 'untracked']], $this->files($status));
});

test('status reports staged unstaged deleted and untracked files', function () {
    $repo = $this->repo('app');
    $this->write($repo, 'staged.txt', "1\n");
    $this->write($repo, 'unstaged.txt', "1\n");
    $this->write($repo, 'removed.txt', "1\n");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'first');
    $this->write($repo, 'staged.txt', "2\n");
    $this->git($repo, ['add', 'staged.txt']);
    $this->write($repo, 'unstaged.txt', "2\n");
    unlink($repo.DIRECTORY_SEPARATOR.'removed.txt');
    $this->write($repo, 'with space.txt', "new\n");
    $this->write($repo, "caf\xc3\xa9.txt", "new\n");

    $status = (new GitRepository($repo))->status();

    self::assertFalse($status->clean);
    self::assertEqualsCanonicalizing([
        ['path' => 'staged.txt', 'index' => 'modified', 'worktree' => null],
        ['path' => 'unstaged.txt', 'index' => null, 'worktree' => 'modified'],
        ['path' => 'removed.txt', 'index' => null, 'worktree' => 'deleted'],
        ['path' => 'with space.txt', 'index' => null, 'worktree' => 'untracked'],
        ['path' => "caf\xc3\xa9.txt", 'index' => null, 'worktree' => 'untracked'],
    ], $this->files($status));
});

test('status reports a rename with its previous path', function () {
    $repo = $this->repo('app');
    $this->write($repo, 'old name.txt', "content that is long enough to be detected as a rename\n");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'first');
    $this->git($repo, ['mv', 'old name.txt', 'new name.txt']);

    $status = (new GitRepository($repo))->status();

    self::assertSame([['path' => 'new name.txt', 'index' => 'renamed', 'worktree' => null, 'previousPath' => 'old name.txt']], $this->files($status));
});

/**
 * array_filter() with no callback also drops the string "0". Here that is a
 * real path, so the record after it was taken as the previous path and lost.
 */
test('status keeps a rename from a file named zero', function () {
    $repo = $this->repo('app');
    $this->write($repo, '0', "content that is long enough to be detected as a rename\n");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'first');
    $this->git($repo, ['mv', '0', 'renamed.txt']);
    $this->write($repo, 'z-added.txt', "something else entirely\n");
    $this->git($repo, ['add', 'z-added.txt']);

    $status = (new GitRepository($repo))->status();

    self::assertEqualsCanonicalizing([
        ['path' => 'renamed.txt', 'index' => 'renamed', 'worktree' => null, 'previousPath' => '0'],
        ['path' => 'z-added.txt', 'index' => 'added', 'worktree' => null],
    ], $this->files($status));
});

test('status reports a merge conflict', function () {
    $repo = $this->repo('app');
    $this->write($repo, 'conflict me.txt', "base\n");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'base');
    $this->git($repo, ['checkout', '-q', '-b', 'side']);
    $this->write($repo, 'conflict me.txt', "side\n");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'side');
    $this->git($repo, ['checkout', '-q', 'main']);
    $this->write($repo, 'conflict me.txt', "main\n");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'main');
    $this->git($repo, ['-c', 'user.name=Ada', '-c', 'user.email=ada@example.com', 'merge', '-q', 'side'], mayFail: true);

    $status = (new GitRepository($repo))->status();

    self::assertSame([['path' => 'conflict me.txt', 'index' => 'conflicted', 'worktree' => 'conflicted']], $this->files($status));
});

test('status reports upstream ahead and behind', function () {
    $origin = $this->repo('origin');
    $this->commit($origin, 'first');
    $clone = $this->clone($origin, 'clone');
    $this->commit($clone, 'local one');
    $this->commit($clone, 'local two');
    $this->commit($origin, 'remote one');
    $this->git($clone, ['fetch', '-q', 'origin']);

    $status = (new GitRepository($clone))->status();

    self::assertSame('main', $status->branch);
    self::assertSame('origin/main', $status->upstream);
    self::assertSame(2, $status->ahead);
    self::assertSame(1, $status->behind);
});

test('status has no branch on a detached head', function () {
    $repo = $this->repo('app');
    $this->commit($repo, 'first');
    $this->git($repo, ['checkout', '-q', '--detach']);

    self::assertNull((new GitRepository($repo))->status()->branch);
});

// --------------------------------------------------------------- info()

test('info of a repository with commits', function () {
    $repo = $this->repo('app');
    $head = $this->commit($repo, 'first');

    $info = (new GitRepository($repo))->info();

    self::assertSame(realpath($repo), realpath($info->root));
    self::assertSame('main', $info->branch);
    self::assertSame($head, $info->head);
    self::assertFalse($info->bare);
});

test('info of a repository with no commits has no head', function () {
    $info = (new GitRepository($this->repo('empty')))->info();

    self::assertSame('main', $info->branch);
    self::assertNull($info->head);
});

test('info has no branch on a detached head', function () {
    $repo = $this->repo('app');
    $head = $this->commit($repo, 'first');
    $this->git($repo, ['checkout', '-q', '--detach']);

    $info = (new GitRepository($repo))->info();

    self::assertNull($info->branch);
    self::assertSame($head, $info->head);
});

// --------------------------------------------------------------- diff()

test('diff of the worktree the index and two refs', function () {
    $repo = $this->repo('app');
    $this->write($repo, 'tracked.txt', "one\n");
    $this->write($repo, 'other.txt', "one\n");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'first');
    $this->write($repo, 'tracked.txt', "two\n");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'second');
    $this->write($repo, 'tracked.txt', "three\n");
    $this->write($repo, 'other.txt', "staged\n");
    $this->git($repo, ['add', 'other.txt']);
    $git = new GitRepository($repo);

    $worktree = $git->diff();
    self::assertSame(['tracked.txt'], $worktree['files']);
    self::assertStringContainsString("-two\n+three\n", $worktree['patch']);

    $staged = $git->diff(staged: true);
    self::assertSame(['other.txt'], $staged['files']);
    self::assertStringContainsString("-one\n+staged\n", $staged['patch']);

    $range = $git->diff('HEAD~1', 'HEAD');
    self::assertSame(['tracked.txt'], $range['files']);
    self::assertStringContainsString("-one\n+two\n", $range['patch']);

    self::assertSame(['tracked.txt'], $git->diff(paths: ['tracked.txt'])['files']);
    self::assertSame([], $git->diff(paths: ['other.txt'])['files']);
});

test('diff lists every file including renames and binaries', function () {
    $repo = $this->repo('app');
    $this->write($repo, 'old.txt', "content that is long enough to be detected as a rename\n");
    $this->write($repo, 'blob.bin', "\x00\x01\x02");
    $this->git($repo, ['add', '.']);
    $this->commit($repo, 'first');
    $this->git($repo, ['mv', 'old.txt', 'new.txt']);
    $this->write($repo, 'blob.bin', "\x00\x03\x04");
    $this->git($repo, ['add', '.']);

    $diff = (new GitRepository($repo))->diff(staged: true);

    self::assertEqualsCanonicalizing(['blob.bin', 'new.txt'], $diff['files']);
    self::assertStringContainsString('GIT binary patch', $diff['patch']);
});

test('diff is empty when nothing changed', function () {
    $repo = $this->repo('app');
    $this->commit($repo, 'first');

    self::assertSame(['patch' => '', 'files' => []], (new GitRepository($repo))->diff());
});

test('diff is empty on a repository with no commits', function () {
    self::assertSame(['patch' => '', 'files' => []], (new GitRepository($this->repo('empty')))->diff());
});

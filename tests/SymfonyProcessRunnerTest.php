<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\Process\SymfonyProcessRunner;

/** The runner's contract, which has to hold whichever way it starts a process. */
test('arguments reach the program unchanged', function () {
    $args = ['plain', 'a b', 'c"d', '100%', '%PATH%', '!x!', 'e^f', 'g&h', 'i|j', '(k)', "l'm", 'trailing\\', 'C:\\a b\\', '', '--flag=1'];

    $result = (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'echo json_encode(array_slice($argv, 1));', '--', ...$args]);

    self::assertSame($args, json_decode($result->stdout, true));
});

test('stdout stderr and a failing exit code are reported', function () {
    $result = (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err");']);
    self::assertSame('out', $result->stdout);
    self::assertSame('err', $result->stderr);
    self::assertSame(0, $result->exitCode);

    try {
        (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'fwrite(STDERR, "conflict here"); exit(3);']);
        self::fail('expected a GitException');
    } catch (GitException $e) {
        self::assertSame(GitErrorCode::Conflict, $e->errorCode);
        self::assertSame(3, $e->getCode());
    }
});

test('large output on both streams does not deadlock', function () {
    $code = 'echo str_repeat("o", 300000); fwrite(STDERR, str_repeat("e", 300000));';

    $result = (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', $code], 30);

    self::assertSame(300000, strlen($result->stdout));
    self::assertSame(300000, strlen($result->stderr));
});

test('a timeout cancels promptly', function () {
    $start = microtime(true);
    try {
        (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'sleep(30);'], 0.5);
        self::fail('expected a GitException');
    } catch (GitException $e) {
        self::assertSame(GitErrorCode::Cancelled, $e->errorCode);
        self::assertStringContainsString('timed out', $e->getMessage());
    }
    self::assertLessThan(15, microtime(true) - $start);
});

/** Laravel's .env loader writes $_ENV, so a GIT_SSH_COMMAND set that way has to reach git. */
test('the child inherits the working directory and the env superglobal', function () {
    $_ENV['FANCY_GIT_FROM_ENV'] = 'b';
    try {
        $result = (new SymfonyProcessRunner)->run([
            PHP_BINARY, '-r',
            'echo json_encode([getcwd(), getenv("FANCY_GIT_FROM_ENV")]);',
        ]);
    } finally {
        unset($_ENV['FANCY_GIT_FROM_ENV']);
    }

    self::assertSame([getcwd(), 'b'], json_decode($result->stdout, true));
});

test('a real git call through the bare name works', function () {
    $result = (new SymfonyProcessRunner)->run(['git', 'version']);

    self::assertStringStartsWith('git version ', $result->stdout);
});

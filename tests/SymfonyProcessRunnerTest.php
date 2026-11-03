<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\Process\SymfonyProcessRunner;
use PHPUnit\Framework\TestCase;

/** The runner's contract, which has to hold whichever way it starts a process. */
final class SymfonyProcessRunnerTest extends TestCase
{
    public function test_arguments_reach_the_program_unchanged(): void
    {
        $args = ['plain', 'a b', 'c"d', '100%', '%PATH%', '!x!', 'e^f', 'g&h', 'i|j', '(k)', "l'm", 'trailing\\', 'C:\\a b\\', '', '--flag=1'];

        $result = (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'echo json_encode(array_slice($argv, 1));', '--', ...$args]);

        self::assertSame($args, json_decode($result->stdout, true));
    }

    public function test_stdout_stderr_and_a_failing_exit_code_are_reported(): void
    {
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
    }

    public function test_large_output_on_both_streams_does_not_deadlock(): void
    {
        $code = 'echo str_repeat("o", 300000); fwrite(STDERR, str_repeat("e", 300000));';

        $result = (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', $code], 30);

        self::assertSame(300000, strlen($result->stdout));
        self::assertSame(300000, strlen($result->stderr));
    }

    public function test_a_timeout_cancels_promptly(): void
    {
        $start = microtime(true);
        try {
            (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'sleep(30);'], 0.5);
            self::fail('expected a GitException');
        } catch (GitException $e) {
            self::assertSame(GitErrorCode::Cancelled, $e->errorCode);
            self::assertStringContainsString('timed out', $e->getMessage());
        }
        self::assertLessThan(15, microtime(true) - $start);
    }

    /** Laravel's .env loader writes $_ENV, so a GIT_SSH_COMMAND set that way has to reach git. */
    public function test_the_child_inherits_the_working_directory_and_the_env_superglobal(): void
    {
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
    }

    public function test_a_real_git_call_through_the_bare_name_works(): void
    {
        $result = (new SymfonyProcessRunner)->run(['git', 'version']);

        self::assertStringStartsWith('git version ', $result->stdout);
    }
}

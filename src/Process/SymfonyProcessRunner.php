<?php

declare(strict_types=1);

namespace FancyGit\Process;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a command by absolute path. A host's own ProcessRunner never comes
 * through here.
 *
 * On Windows this does not use Symfony Process, even with an absolute git: every
 * command there goes through a cmd.exe it finds on PATH (an empty or relative
 * entry included, falling back to the bare name), and a timeout runs a bare
 * taskkill. Both are lookups the working directory can answer. PHP's own
 * proc_open with an array starts the program directly, with no shell.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    private const WINDOWS_NULL = 'NUL';

    public function run(array $command, ?float $timeout = null): ProcessResult
    {
        $command[0] = ExecutableResolver::resolve((string) $command[0]);

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->runOnWindows($command, $timeout);
        }

        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new GitException(GitErrorCode::Cancelled, 'Git operation timed out.');
        }

        if (! $process->isSuccessful()) {
            throw GitException::fromOutput($process->getErrorOutput() ?: $process->getOutput(), $process->getExitCode() ?? 1);
        }

        return new ProcessResult($process->getOutput(), $process->getErrorOutput(), $process->getExitCode() ?? 0);
    }

    /** @param list<string> $command */
    private function runOnWindows(array $command, ?float $timeout): ProcessResult
    {
        // Files rather than pipes: stream_select does not work on Windows pipes,
        // and a blocking read of one stream deadlocks when the other fills.
        // tmpfile() removes itself on close.
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            throw new GitException(GitErrorCode::Unknown, 'Could not open temporary files for the Git process output.');
        }

        $process = @proc_open(
            $command,
            [0 => ['file', self::WINDOWS_NULL, 'r'], 1 => $stdout, 2 => $stderr],
            $pipes,
            getcwd() ?: null,
            $this->environment(),
            ['bypass_shell' => true, 'suppress_errors' => true],
        );
        if (! is_resource($process)) {
            throw new GitException(GitErrorCode::Unknown, "Could not start \"{$command[0]}\".");
        }

        $deadline = $timeout !== null && $timeout > 0 ? microtime(true) + $timeout : null;
        $wait = 1000;
        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                $this->killTree($status['pid']);
                proc_terminate($process);
                proc_close($process);

                throw new GitException(GitErrorCode::Cancelled, 'Git operation timed out.');
            }
            usleep($wait);
            $wait = min($wait * 2, 20000);
        }
        proc_close($process);

        rewind($stdout);
        rewind($stderr);
        $out = (string) stream_get_contents($stdout);
        $err = (string) stream_get_contents($stderr);

        if ($exitCode !== 0) {
            throw GitException::fromOutput($err ?: $out, $exitCode);
        }

        return new ProcessResult($out, $err, $exitCode);
    }

    /** git.exe on PATH is usually a launcher, so killing only it would leave the real git running. */
    private function killTree(int $pid): void
    {
        $taskkill = getenv('SystemRoot').'\\System32\\taskkill.exe';
        if (! ExecutableResolver::isAbsolute($taskkill, true) || ! is_file($taskkill)) {
            return;
        }

        $process = @proc_open(
            [$taskkill, '/F', '/T', '/PID', (string) $pid],
            [0 => ['file', self::WINDOWS_NULL, 'r'], 1 => ['file', self::WINDOWS_NULL, 'w'], 2 => ['file', self::WINDOWS_NULL, 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true, 'suppress_errors' => true],
        );
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /**
     * The environment Symfony Process gives a child, so this branch differs only in
     * how the program is started. Laravel's .env loader writes $_ENV.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = (array) getenv();
        $env = array_intersect_ukey($env, $_SERVER, 'strcasecmp') ?: $env;
        $fromSuperglobal = array_filter($_ENV, 'is_string');

        return $fromSuperglobal + array_diff_ukey($env, $fromSuperglobal, 'strcasecmp');
    }
}

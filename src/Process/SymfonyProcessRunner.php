<?php

declare(strict_types=1);

namespace FancyGit\Process;

use FancyGit\Error\GitException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, ?float $timeout = null): ProcessResult
    {
        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new GitException(\FancyGit\Error\GitErrorCode::Cancelled, 'Git operation timed out.');
        }

        if (! $process->isSuccessful()) {
            throw GitException::fromOutput($process->getErrorOutput() ?: $process->getOutput(), $process->getExitCode() ?? 1);
        }

        return new ProcessResult($process->getOutput(), $process->getErrorOutput(), $process->getExitCode() ?? 0);
    }
}

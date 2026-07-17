<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\Process\ProcessResult;
use FancyGit\Process\ProcessRunner;

final class StubProcessRunner implements ProcessRunner
{
    public array $calls = [];

    public function __construct(private array $outputs = []) {}

    public function run(array $command, ?float $timeout = null): ProcessResult
    {
        $this->calls[] = $command;

        return new ProcessResult(array_shift($this->outputs) ?? '');
    }
}

<?php

declare(strict_types=1);

namespace FancyGit\Process;

interface ProcessRunner
{
    /** @param list<string> $command */
    public function run(array $command, ?float $timeout = null): ProcessResult;
}

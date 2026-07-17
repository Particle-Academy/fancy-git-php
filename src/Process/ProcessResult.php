<?php

declare(strict_types=1);

namespace FancyGit\Process;

final readonly class ProcessResult
{
    public function __construct(
        public string $stdout,
        public string $stderr = '',
        public int $exitCode = 0,
    ) {}
}

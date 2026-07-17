<?php

declare(strict_types=1);

namespace FancyGit\Error;

use RuntimeException;

final class GitException extends RuntimeException
{
    public function __construct(
        public readonly GitErrorCode $errorCode,
        string $message,
        int $exitCode = 0,
    ) {
        parent::__construct(self::redact($message), $exitCode);
    }

    public static function fromOutput(string $output, int $exitCode = 0): self
    {
        $lower = strtolower($output);
        $code = match (true) {
            str_contains($lower, 'authentication'), str_contains($lower, 'permission denied') => GitErrorCode::Auth,
            str_contains($lower, 'conflict'), str_contains($lower, 'unmerged') => GitErrorCode::Conflict,
            str_contains($lower, 'local changes'), str_contains($lower, 'would be overwritten') => GitErrorCode::DirtyWorktree,
            str_contains($lower, 'non-fast-forward'), str_contains($lower, 'fetch first') => GitErrorCode::NonFastForward,
            str_contains($lower, 'not found'), str_contains($lower, 'unknown revision') => GitErrorCode::NotFound,
            default => GitErrorCode::Unknown,
        };

        return new self($code, $output, $exitCode);
    }

    public static function redact(string $message): string
    {
        return preg_replace(
            [
                '/gh[pousr]_[A-Za-z0-9_]{20,}/',
                '/glpat-[A-Za-z0-9_-]{20,}/',
                '/(?:Bearer|token|password)\s+[^\s]+/i',
                '/https?:\/\/[^\/@\s]+@/',
            ],
            '[REDACTED]',
            $message,
        ) ?? $message;
    }
}

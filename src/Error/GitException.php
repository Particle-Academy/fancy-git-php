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

    /**
     * Remove credentials by their SHAPE or their CONTEXT — never simply the word
     * after "token" or "password". That rule blanked ordinary provider text
     * ("Token scope insufficient" became "[REDACTED] insufficient") while missing
     * a bare `PRIVATE-TOKEN: …` header, `Authorization: Basic …`, a
     * `private_token=` query parameter, GitLab's non-PAT tokens and GitHub
     * fine-grained PATs entirely.
     *
     * Kept identical to `redactSecrets()` in fancy-git-js; both suites read the
     * same `tests/fixtures/redaction-cases.json`.
     */
    public static function redact(string $message): string
    {
        $patterns = [
            // Credential headers: keep the header name and auth scheme, drop the value.
            '/\b((?:proxy-)?authorization\s*:\s*(?:(?:bearer|basic|token)\s+)?)[^\s,;\'"]+/i' => '${1}[REDACTED]',
            '/\b((?:private|job|deploy)-token\s*:\s*)[^\s,;\'"]+/i' => '${1}[REDACTED]',
            // A credential in a query string or an assignment: private_token=, access_token=, password=, client_secret=.
            '/\b([\w-]*(?:token|password|passwd|secret)=)[^\s&#,;\'"]+/i' => '${1}[REDACTED]',
            // Host-issued token shapes, wherever they appear.
            '/gh[pousr]_[A-Za-z0-9_]{20,}/' => '[REDACTED]',
            '/github_pat_[A-Za-z0-9_]{20,}/' => '[REDACTED]',
            '/gl(?:pat|oas|dt|rtr|rt|cbt|ptt|ft|imt|agent|wt|soat|ffct)-[A-Za-z0-9_-]{20,}/' => '[REDACTED]',
            // A bearer value outside a header, when it looks like a credential rather than a word.
            '/\b(bearer\s+)(?=[A-Za-z0-9._~+\/-]*\d)[A-Za-z0-9._~+\/-]{16,}=*/i' => '${1}[REDACTED]',
            // Userinfo in a URL: https://user:secret@host.
            '/https?:\/\/[^\/@\s]+@/' => '[REDACTED]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $message) ?? $message;
    }
}

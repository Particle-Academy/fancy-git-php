<?php

declare(strict_types=1);

namespace FancyGit\Error;

enum GitErrorCode: string
{
    case Auth = 'auth';
    case Conflict = 'conflict';
    case DirtyWorktree = 'dirty_worktree';
    case NotFound = 'not_found';
    case NonFastForward = 'non_fast_forward';
    case RateLimited = 'rate_limited';
    case Cancelled = 'cancelled';
    case Unsupported = 'unsupported';
    case InvalidArgument = 'invalid_argument';
    case Unknown = 'unknown';
}

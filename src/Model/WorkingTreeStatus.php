<?php

declare(strict_types=1);

namespace FancyGit\Model;

final readonly class WorkingTreeStatus implements \JsonSerializable
{
    /** @param list<FileChange> $files */
    public function __construct(
        public ?string $branch,
        public ?string $upstream,
        public int $ahead,
        public int $behind,
        public array $files,
        public bool $clean,
    ) {}

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

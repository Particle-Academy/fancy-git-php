<?php

declare(strict_types=1);

namespace FancyGit\Model;

final readonly class FileChange implements \JsonSerializable
{
    public function __construct(
        public string $path,
        public ?string $index,
        public ?string $worktree,
        public ?string $previousPath = null,
    ) {}

    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null);
    }
}

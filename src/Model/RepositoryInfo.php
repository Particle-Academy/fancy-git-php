<?php

declare(strict_types=1);

namespace FancyGit\Model;

final readonly class RepositoryInfo implements \JsonSerializable
{
    public function __construct(
        public string $root,
        public ?string $branch,
        public ?string $head,
        public bool $bare,
    ) {}

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

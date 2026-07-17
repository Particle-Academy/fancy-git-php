<?php

declare(strict_types=1);

namespace FancyGit\Model;

final readonly class Commit implements \JsonSerializable
{
    /** @param list<string> $parents */
    public function __construct(
        public string $id,
        public string $shortId,
        public array $parents,
        public string $authorName,
        public string $authorEmail,
        public string $authoredAt,
        public string $subject,
    ) {}

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

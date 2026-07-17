<?php

declare(strict_types=1);

namespace FancyGit\Model;

final readonly class OperationProposal implements \JsonSerializable
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $operation,
        public array $arguments,
        public string $summary,
    ) {}

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

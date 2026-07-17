<?php

declare(strict_types=1);

namespace FancyGit\Provider;

interface GitProvider
{
    public function kind(): string;

    /** @param array{name:string,fetchUrl:string,pushUrl?:string} $remote
     *  @return array{provider:string,owner:string,name:string,baseUrl?:string}|null */
    public function identify(array $remote): ?array;

    /** @return array<string, mixed> */
    public function repository(array $ref): array;

    /** @return array{items:list<array<string,mixed>>,nextCursor?:string,total?:int} */
    public function listReviews(array $ref, array $query = []): array;

    /** @return array<string, mixed> */
    public function getReview(array $ref, int $number): array;

    /** @return array<string, mixed> */
    public function createReview(array $ref, array $input): array;

    /** @return array<string, mixed> */
    public function compare(array $ref, string $base, string $head): array;

    /** @return list<array<string, mixed>> */
    public function checks(array $ref, string $revision): array;
}

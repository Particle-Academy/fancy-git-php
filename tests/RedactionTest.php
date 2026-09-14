<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Error-message redaction, in both directions.
 *
 * The cases live in `tests/fixtures/redaction-cases.json`, a byte-for-byte copy
 * of the one fancy-git-js's redaction.test.ts reads, so the two runtimes are
 * held to the same table. `{}` splits token-shaped fakes so secret scanners do
 * not flag the file, and is removed here before use.
 */
final class RedactionTest extends TestCase
{
    /** @return array{redacts: list<array{id: string, input: string, expected: string}>, keeps: list<array{id: string, input: string}>} */
    private static function table(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/redaction-cases.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    private static function unsplit(string $value): string
    {
        return str_replace('{}', '', $value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function secrets(): iterable
    {
        foreach (self::table()['redacts'] as $case) {
            yield $case['id'] => [self::unsplit($case['input']), self::unsplit($case['expected'])];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryText(): iterable
    {
        foreach (self::table()['keeps'] as $case) {
            yield $case['id'] => [self::unsplit($case['input'])];
        }
    }

    #[DataProvider('secrets')]
    public function test_it_redacts_a_real_secret(string $input, string $expected): void
    {
        $redacted = GitException::redact($input);

        self::assertSame($expected, $redacted);
        self::assertStringContainsString('[REDACTED]', $redacted);
    }

    #[DataProvider('ordinaryText')]
    public function test_it_keeps_ordinary_provider_text_intact(string $input): void
    {
        self::assertSame($input, GitException::redact($input));
    }

    public function test_it_is_applied_to_every_git_exception_message(): void
    {
        $token = self::unsplit('gl{}pat-FAKEfakeFAKEfake1234');

        self::assertSame('PRIVATE-TOKEN: [REDACTED]', (new GitException(GitErrorCode::Auth, "PRIVATE-TOKEN: {$token}"))->getMessage());
        self::assertStringNotContainsString($token, GitException::fromOutput("fatal: Authentication failed for 'https://oauth2:{$token}@gitlab.com/a/b.git/'")->getMessage());
        self::assertSame('Token scope insufficient', (new GitException(GitErrorCode::Auth, 'Token scope insufficient'))->getMessage());
    }
}

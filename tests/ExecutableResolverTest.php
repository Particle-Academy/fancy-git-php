<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\Process\ExecutableResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs on every platform, including Ubuntu CI: the PATH walk takes its platform
 * and its file check as inputs. The cases are the same file in fancy-git-js and
 * change-council, so a behaviour change has to be made in all three.
 */
final class ExecutableResolverTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>}> */
    public static function lookupCases(): array
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/fixtures/executable-lookup-cases.json'), true);

        $out = [];
        foreach ($cases as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    #[DataProvider('lookupCases')]
    public function test_lookup(array $case): void
    {
        $found = ExecutableResolver::find(
            $case['name_to_find'],
            $case['PATH'],
            $case['PATHEXT'],
            $case['platform'] === 'win32',
            fn (string $path): bool => in_array($path, $case['files'], true),
        );

        self::assertSame($case['expect'], $found);
    }

    public function test_the_fixture_has_cases_to_run(): void
    {
        self::assertGreaterThan(20, count(self::lookupCases()));
    }

    #[DataProvider('absoluteCases')]
    public function test_is_absolute(string $entry, bool $windows, bool $expected): void
    {
        self::assertSame($expected, ExecutableResolver::isAbsolute($entry, $windows));
    }

    /** @return list<array{string, bool, bool}> */
    public static function absoluteCases(): array
    {
        return [
            ['C:\\x', true, true],
            ['c:/x', true, true],
            ['\\\\server\\share', true, true],
            ['//server/share', true, true],
            ['\\\\server', true, false],
            ['C:', true, false],
            ['C:x', true, false],
            ['\\x', true, false],
            ['/x', true, false],
            ['.', true, false],
            ['', true, false],
            ['/usr/bin', false, true],
            ['C:\\x', false, false],
            ['.', false, false],
            ['', false, false],
            ['bin', false, false],
        ];
    }

    public function test_an_absolute_path_is_returned_as_given_so_a_host_can_name_its_own_git(): void
    {
        self::assertSame(PHP_BINARY, ExecutableResolver::resolve(PHP_BINARY));
    }

    #[DataProvider('relativeNames')]
    public function test_a_relative_path_is_refused(string $name): void
    {
        try {
            ExecutableResolver::resolve($name);
            self::fail('expected a GitException');
        } catch (GitException $e) {
            self::assertSame(GitErrorCode::InvalidArgument, $e->errorCode);
        }
    }

    /** @return list<array{string}> */
    public static function relativeNames(): array
    {
        return [['./git'], ['bin/git'], ['..\\git'], ['bin\\git']];
    }

    public function test_nothing_found_fails_closed_with_a_clear_error(): void
    {
        try {
            ExecutableResolver::resolve('no-such-program-fancy-git');
            self::fail('expected a GitException');
        } catch (GitException $e) {
            self::assertSame(GitErrorCode::NotFound, $e->errorCode);
            self::assertStringContainsString('current directory is never used', $e->getMessage());
        }
    }

    public function test_open_basedir_hiding_git_stays_a_bare_name_on_posix_only_when_path_is_all_absolute(): void
    {
        self::assertTrue(ExecutableResolver::bareNameIsSafe('/usr/bin:/bin', false));
        self::assertFalse(ExecutableResolver::bareNameIsSafe('.:/usr/bin', false));
        self::assertFalse(ExecutableResolver::bareNameIsSafe('/usr/bin:', false));
        self::assertFalse(ExecutableResolver::bareNameIsSafe('', false));
        self::assertFalse(ExecutableResolver::bareNameIsSafe('C:\\Git\\cmd', true));
    }
}

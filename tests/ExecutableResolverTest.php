<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\Process\ExecutableResolver;
use PHPUnit\Framework\TestCase;

/**
 * Runs on every platform, including Ubuntu CI: the PATH walk takes its platform
 * and its file check as inputs. The cases are the same file in fancy-git-js and
 * change-council, so a behaviour change has to be made in all three.
 */
abstract class ExecutableResolverCase extends TestCase
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

    /** @return list<array{string}> */
    public static function relativeNames(): array
    {
        return [['./git'], ['bin/git'], ['..\\git'], ['bin\\git']];
    }
}

uses(ExecutableResolverCase::class);

test('lookup', function (array $case) {
    $found = ExecutableResolver::find(
        $case['name_to_find'],
        $case['PATH'],
        $case['PATHEXT'],
        $case['platform'] === 'win32',
        fn (string $path): bool => in_array($path, $case['files'], true),
    );

    self::assertSame($case['expect'], $found);
})->with(ExecutableResolverCase::lookupCases());

test('the fixture has cases to run', function () {
    self::assertGreaterThan(20, count(self::lookupCases()));
});

test('is absolute', function (string $entry, bool $windows, bool $expected) {
    self::assertSame($expected, ExecutableResolver::isAbsolute($entry, $windows));
})->with(ExecutableResolverCase::absoluteCases());

test('an absolute path is returned as given so a host can name its own git', function () {
    self::assertSame(PHP_BINARY, ExecutableResolver::resolve(PHP_BINARY));
});

test('a relative path is refused', function (string $name) {
    try {
        ExecutableResolver::resolve($name);
        self::fail('expected a GitException');
    } catch (GitException $e) {
        self::assertSame(GitErrorCode::InvalidArgument, $e->errorCode);
    }
})->with(ExecutableResolverCase::relativeNames());

test('nothing found fails closed with a clear error', function () {
    try {
        ExecutableResolver::resolve('no-such-program-fancy-git');
        self::fail('expected a GitException');
    } catch (GitException $e) {
        self::assertSame(GitErrorCode::NotFound, $e->errorCode);
        self::assertStringContainsString('current directory is never used', $e->getMessage());
    }
});

test('open basedir hiding git stays a bare name on posix only when path is all absolute', function () {
    self::assertTrue(ExecutableResolver::bareNameIsSafe('/usr/bin:/bin', false));
    self::assertFalse(ExecutableResolver::bareNameIsSafe('.:/usr/bin', false));
    self::assertFalse(ExecutableResolver::bareNameIsSafe('/usr/bin:', false));
    self::assertFalse(ExecutableResolver::bareNameIsSafe('', false));
    self::assertFalse(ExecutableResolver::bareNameIsSafe('C:\\Git\\cmd', true));
});

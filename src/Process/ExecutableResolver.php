<?php

declare(strict_types=1);

namespace FancyGit\Process;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;

/**
 * Which file a bare program name runs must not depend on the working directory.
 * This walks PATH itself, absolute entries only, and the runner starts the
 * absolute path it returns.
 *
 * Not Symfony's ExecutableFinder: that treats an empty PATH entry as ".", accepts
 * relative entries, and returns the current directory's copy when they come first.
 * It also tries each extension across every directory, so a later directory's
 * .COM beats an earlier directory's .EXE.
 *
 * Kept identical to `executable.ts` in fancy-git-js and `findExecutable` in
 * change-council; all three read the same `executable-lookup-cases.json`.
 *
 * @internal
 */
final class ExecutableResolver
{
    /** What CreateProcess can start without a shell. */
    private const RUNNABLE_EXTENSIONS = ['.com', '.exe'];

    /** cmd.exe re-parses a batch file's arguments, so one is never started for an untrusted ref. */
    private const BATCH_EXTENSIONS = ['.bat', '.cmd'];

    private const DEFAULT_PATHEXT = '.COM;.EXE;.BAT;.CMD';

    /** @var array<string, string> */
    private static array $resolved = [];

    /**
     * @param  (callable(string): bool)|null  $isFile
     * @return array{path: ?string, batch: ?string} `path` is the file to run. `batch` is the first batch file seen under this name, never returned as `path`.
     */
    public static function find(string $name, string $path, ?string $pathExt, bool $windows, ?callable $isFile = null): array
    {
        $isFile ??= static fn (string $file): bool => @is_file($file) && ($windows || @is_executable($file));
        $separator = $windows ? '\\' : '/';

        $hasExtension = preg_match('/\.(?:com|exe|bat|cmd)$/i', $name) === 1;
        $extensions = ! $windows || $hasExtension
            ? ['']
            : array_values(array_filter(explode(';', $pathExt ?: self::DEFAULT_PATHEXT), static fn (string $ext): bool => $ext !== ''));

        $batch = null;
        foreach (explode($windows ? ';' : ':', $path) as $raw) {
            $entry = $windows ? (preg_replace('/^"(.*)"$/', '$1', $raw) ?? $raw) : $raw;
            if (! self::isAbsolute($entry, $windows)) {
                continue;
            }
            $directory = rtrim($entry, $windows ? '\\/' : '/');
            foreach ($extensions as $extension) {
                $candidate = $directory.$separator.$name.$extension;
                if (! $isFile($candidate)) {
                    continue;
                }
                if (! $windows) {
                    return ['path' => $candidate, 'batch' => $batch];
                }
                $kind = preg_match('/\.[^.\\\\\/]*$/', $candidate, $match) === 1 ? strtolower($match[0]) : '';
                if (in_array($kind, self::RUNNABLE_EXTENSIONS, true)) {
                    return ['path' => $candidate, 'batch' => $batch];
                }
                if (in_array($kind, self::BATCH_EXTENSIONS, true)) {
                    $batch ??= $candidate;
                }
            }
        }

        return ['path' => null, 'batch' => $batch];
    }

    public static function isAbsolute(string $entry, bool $windows): bool
    {
        if (! $windows) {
            return str_starts_with($entry, '/');
        }

        // Not "starts with a slash": "\foo" is relative to the current drive, and
        // so still depends on the working directory.
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $entry) === 1
            || preg_match('/^[\\\\\/]{2}[^\\\\\/]+[\\\\\/][^\\\\\/]+/', $entry) === 1;
    }

    /**
     * open_basedir can hide a real git from is_file() while still letting it run.
     * On POSIX the kernel's PATH walk never looks at the cwd unless PATH tells it
     * to, so a bare name is exactly as safe as this resolver when every entry is
     * absolute. Windows has no such guarantee.
     */
    public static function bareNameIsSafe(string $path, bool $windows): bool
    {
        if ($windows || $path === '') {
            return false;
        }
        foreach (explode(':', $path) as $entry) {
            if (! self::isAbsolute($entry, false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The path to start for `$name`, cached for the life of the process. An
     * absolute `$name` is returned as given, which is how a host names its own
     * git. A failure is not cached, so fixing PATH recovers without a restart.
     */
    public static function resolve(string $name): string
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        if (self::isAbsolute($name, $windows)) {
            return $name;
        }
        if (preg_match('/[\\\\\/]/', $name) === 1) {
            throw new GitException(GitErrorCode::InvalidArgument, "Refusing to run \"{$name}\": give a bare name or an absolute path.");
        }

        if (isset(self::$resolved[$name])) {
            return self::$resolved[$name];
        }

        $path = (string) getenv('PATH');
        $found = self::find($name, $path, getenv('PATHEXT') ?: null, $windows);
        if ($found['path'] !== null) {
            return self::$resolved[$name] = $found['path'];
        }
        if ($found['batch'] !== null) {
            throw new GitException(GitErrorCode::NotFound, "Found \"{$found['batch']}\" on PATH, but a batch file cannot be run without a shell. Add a real {$name} executable to PATH, or pass a ProcessRunner that calls your own copy.");
        }

        if ((string) ini_get('open_basedir') !== '') {
            if (self::bareNameIsSafe($path, $windows)) {
                return $name;
            }

            throw new GitException(GitErrorCode::NotFound, "Could not find the \"{$name}\" executable: open_basedir may be hiding it, and it cannot be run by name safely here. Pass a ProcessRunner that calls your own copy by absolute path.");
        }

        throw new GitException(GitErrorCode::NotFound, "Could not find the \"{$name}\" executable. Only absolute PATH entries are searched; the current directory is never used. Add {$name} to PATH, or pass a ProcessRunner that calls your own copy.");
    }
}

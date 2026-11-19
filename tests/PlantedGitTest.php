<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A same-named executable in the working directory must never run in place of
 * git. Each scenario starts with a CONTROL that makes the old call in the same
 * environment and requires the plant to fire: without it, a machine that does
 * not search the cwd would pass every assertion here on vulnerable code. The
 * ambient NoDefaultCurrentDirectoryInExePath is exactly that, so it is removed
 * from the child's environment and the child reports that it is gone.
 */
abstract class PlantedGitCase extends TestCase
{
    protected string $root;

    protected static ?string $nodeCopy = null;

    protected function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path.DIRECTORY_SEPARATOR.$entry);
                }
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }

    protected function dir(string $name): string
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.$name;
        mkdir($dir);

        return $dir;
    }

    /** @return list<string> */
    protected function markers(string $dir): array
    {
        return array_values(array_filter(scandir($dir), fn ($file) => str_ends_with($file, '.marker')));
    }

    protected function clearMarkers(string $dir): void
    {
        foreach ($this->markers($dir) as $marker) {
            unlink($dir.DIRECTORY_SEPARATOR.$marker);
        }
    }

    /** Deliberately not the code under test. */
    protected function realGit(): string
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = $dir.DIRECTORY_SEPARATOR.($windows ? 'git.exe' : 'git');
            if (preg_match($windows ? '/^[A-Za-z]:[\\\\\/]/' : '/^\//', $dir) === 1 && is_file($candidate)) {
                return $candidate;
            }
        }
        self::fail('this suite needs a real git on PATH');
    }

    /** @return array<string, mixed> */
    protected function probe(string $cwd, string $path, string $mode, array $extraEnv = [], array $args = []): array
    {
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (strcasecmp($key, 'NoDefaultCurrentDirectoryInExePath') === 0 || strcasecmp($key, 'PATH') === 0) {
                unset($env[$key]);
            }
        }
        $env['PATH'] = $path;
        $env += $extraEnv;

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/fixtures/planted-probe.php', $mode, ...$args],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $env,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode((string) $stdout, true);
        self::assertIsArray($result, 'probe printed: '.$stdout.$stderr);
        self::assertFalse($result['ambient'], 'the ambient variable must be gone, or the control proves nothing');

        return $result;
    }

    protected function plantBatch(string $dir, string $name = 'git'): void
    {
        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.$name.'.cmd',
            '@echo ran > "%~dp0ran-'.$name.'.cmd.marker"'."\r\nexit /b 7\r\n",
        );
    }

    /**
     * A real .exe plant, built from Node: a copy under another name, with the
     * preload in NODE_OPTIONS. `git version` is the probe rather than
     * `git --version`, which node answers itself before running a preload.
     *
     * @return array<string, string> the environment that arms it
     */
    protected function plantExe(string $dir, string $name = 'git.exe'): array
    {
        $node = $this->findNode();
        copy($node, $dir.DIRECTORY_SEPARATOR.$name);
        $script = $this->root.DIRECTORY_SEPARATOR.'plant.cjs';
        file_put_contents($script, <<<'JS'
const { basename, dirname, join } = require("node:path");
const self = basename(process.execPath).toLowerCase();
if (self === "git.exe" || self === "cmd.exe" || self === "taskkill.exe") {
  require("node:fs").writeFileSync(join(dirname(process.execPath), "ran-" + self + ".marker"), "ran");
  process.exit(7);
}

JS);

        return ['NODE_OPTIONS' => '--require "'.str_replace('\\', '/', $script).'"'];
    }

    protected function findNode(): string
    {
        if (self::$nodeCopy !== null) {
            return self::$nodeCopy;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file($dir.'\\node.exe')) {
                return self::$nodeCopy = $dir.'\\node.exe';
            }
        }
        self::markTestSkipped('the .exe plant is built from node, which is not on PATH');
    }

    protected function gitDir(): string
    {
        return dirname($this->realGit());
    }

    protected function systemDir(): string
    {
        return getenv('SystemRoot').'\\System32';
    }

    protected function phpDir(): string
    {
        return dirname(PHP_BINARY);
    }

    /** @return array<string, mixed> */
    protected function timeoutProbe(string $cwd, array $extraEnv): array
    {
        $script = $this->root.DIRECTORY_SEPARATOR.'timeout.php';
        $autoload = var_export(dirname(__DIR__).'/vendor/autoload.php', true);
        file_put_contents($script, <<<PHP
<?php
require {$autoload};
try {
    (new FancyGit\Process\SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'sleep(20);'], 0.5);
    echo json_encode(['cancelled' => false]);
} catch (FancyGit\Error\GitException \$e) {
    echo json_encode(['cancelled' => \$e->errorCode === FancyGit\Error\GitErrorCode::Cancelled]);
}
PHP);
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (strcasecmp($key, 'NoDefaultCurrentDirectoryInExePath') === 0) {
                unset($env[$key]);
            }
        }
        $env += $extraEnv;
        $process = proc_open([PHP_BINARY, $script], [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, $cwd, $env);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return json_decode((string) $stdout, true) ?? ['raw' => $stdout];
    }

    protected function posixPlantScenario(): void
    {
        $cwd = $this->dir('cwd');
        $script = $cwd.'/git';
        file_put_contents($script, "#!/bin/sh\necho ran > \"\$(dirname \"\$0\")/ran-git.marker\"\nexit 7\n");
        chmod($script, 0755);
        $path = '.:'.getenv('PATH');

        self::assertSame(7, $this->probe($cwd, $path, 'bare')['exit']);
        self::assertSame(['ran-git.marker'], $this->markers($cwd));
        $this->clearMarkers($cwd);

        $fixed = $this->probe($cwd, $path, 'runner');
        self::assertStringStartsWith('git version ', $fixed['stdout'] ?? json_encode($fixed));
        self::assertSame([], $this->markers($cwd));

        $closed = $this->probe($cwd, '.', 'runner');
        self::assertSame('not_found', $closed['code'] ?? null, json_encode($closed));
        self::assertSame([], $this->markers($cwd));
    }

    protected function initRepo(string $dir): void
    {
        $process = proc_open(
            [$this->realGit(), 'init', '-q', $dir],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
    }
}

uses(PlantedGitCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-git-plant-'.bin2hex(random_bytes(6));
    mkdir($this->root);
});

afterEach(function () {
    $this->remove($this->root);
});

test('a plant on dot path runs under a bare name and not through the runner', function () {
    $cwd = $this->dir('cwd');
    $env = $this->plantExe($cwd);
    $path = '.;'.$this->gitDir();

    $control = $this->probe($cwd, $path, 'bare', $env);
    self::assertSame(7, $control['exit']);
    self::assertSame(['ran-git.exe.marker'], $this->markers($cwd));
    $this->clearMarkers($cwd);

    $fixed = $this->probe($cwd, $path, 'runner', $env);
    self::assertStringStartsWith('git version ', $fixed['stdout'] ?? json_encode($fixed));
    self::assertSame([], $this->markers($cwd));
})->onlyOnWindows();

test('a plant on an empty path entry runs under a bare name and not through the runner', function () {
    $cwd = $this->dir('cwd');
    $env = $this->plantExe($cwd);
    $path = ';'.$this->gitDir();

    self::assertSame(7, $this->probe($cwd, $path, 'bare', $env)['exit']);
    self::assertSame(['ran-git.exe.marker'], $this->markers($cwd));
    $this->clearMarkers($cwd);

    $fixed = $this->probe($cwd, $path, 'runner', $env);
    self::assertStringStartsWith('git version ', $fixed['stdout'] ?? json_encode($fixed));
    self::assertSame([], $this->markers($cwd));
})->onlyOnWindows();

test('a plant on a relative path entry is not searched', function () {
    $cwd = $this->dir('cwd');
    $sub = $cwd.DIRECTORY_SEPARATOR.'sub';
    mkdir($sub);
    $env = $this->plantExe($sub);
    $path = 'sub;'.$this->gitDir();

    self::assertSame(7, $this->probe($cwd, $path, 'bare', $env)['exit']);
    self::assertSame(['ran-git.exe.marker'], $this->markers($sub));
    $this->clearMarkers($sub);

    $fixed = $this->probe($cwd, $path, 'runner', $env);
    self::assertStringStartsWith('git version ', $fixed['stdout'] ?? json_encode($fixed));
    self::assertSame([], $this->markers($sub));
})->onlyOnWindows();

/** The shape that fires with no PATH trickery: git is simply not installed where PATH says. */
test('git only in the cwd fails closed', function () {
    foreach (['exe', 'cmd'] as $kind) {
        $cwd = $this->dir('cwd-'.$kind);
        $env = [];
        if ($kind === 'exe') {
            $env = $this->plantExe($cwd);
        } else {
            $this->plantBatch($cwd);
        }
        $path = $this->systemDir().';'.$this->phpDir();

        self::assertSame(7, $this->probe($cwd, $path, 'bare', $env)['exit'], $kind);
        self::assertNotSame([], $this->markers($cwd), $kind);
        $this->clearMarkers($cwd);

        $fixed = $this->probe($cwd, $path, 'runner', $env);
        self::assertSame('not_found', $fixed['code'] ?? null, json_encode($fixed));
        self::assertStringContainsString('current directory is never used', $fixed['message']);
        self::assertSame([], $this->markers($cwd), $kind);
    }
})->onlyOnWindows();

/**
 * Symfony's own wrapper is a second lookup, so an absolute git alone does not close this.
 *
 * The control is the suite's own vulnerable runner (a bare `cmd` through
 * proc_open), not symfony/process: from 8.1.10 Symfony resolves cmd.exe by full
 * path itself, and a control borrowed from it would silently stop firing. The
 * control's job is to prove THIS environment would run the plant, whatever any
 * library version does.
 */
test('a planted cmd exe does not run', function () {
    $cwd = $this->dir('cwd');
    $env = $this->plantExe($cwd, 'cmd.exe');

    foreach (['.;'.$this->gitDir().';'.$this->systemDir(), ';'.$this->gitDir().';'.$this->systemDir(), $this->gitDir()] as $path) {
        self::assertSame(7, $this->probe($cwd, $path, 'vulnerable', $env, ['cmd', '/D', '/C', 'git', 'version'])['exit'], $path);
        self::assertSame(['ran-cmd.exe.marker'], $this->markers($cwd), $path);
        $this->clearMarkers($cwd);

        $fixed = $this->probe($cwd, $path, 'runner', $env);
        self::assertStringStartsWith('git version ', $fixed['stdout'] ?? json_encode($fixed));
        self::assertSame([], $this->markers($cwd), $path);
    }
})->onlyOnWindows();

test('a timed out command does not run a planted taskkill', function () {
    $cwd = $this->dir('cwd');
    $env = $this->plantExe($cwd, 'taskkill.exe');

    $out = $this->timeoutProbe($cwd, $env);
    self::assertTrue($out['cancelled'], json_encode($out));
    self::assertSame([], $this->markers($cwd));
})->onlyOnWindows();

test('an absolute path entry is found identified by the plant it runs there', function () {
    $onPath = $this->dir('on-path');
    $cwd = $this->dir('cwd');
    $env = $this->plantExe($onPath);

    $out = $this->probe($cwd, $onPath.';'.$this->systemDir(), 'runner', $env);
    self::assertSame(7, $out['exit'] ?? null, json_encode($out));
    self::assertSame(['ran-git.exe.marker'], $this->markers($onPath));
    self::assertSame([], $this->markers($cwd));
})->onlyOnWindows();

test('a batch file git on path fails clearly and does not run', function () {
    $onPath = $this->dir('on-path');
    $cwd = $this->dir('cwd');
    $this->plantBatch($onPath);

    $out = $this->probe($cwd, $onPath.';'.$this->systemDir(), 'runner');
    self::assertSame('not_found', $out['code'] ?? null, json_encode($out));
    self::assertStringContainsString('batch file', $out['message']);
    self::assertSame([], $this->markers($onPath));
})->onlyOnWindows();

test('a normal repository with a plant inside it is read with real git', function () {
    $cwd = $this->dir('repo');
    $env = $this->plantExe($cwd);
    $this->initRepo($cwd);

    // Empty entry first, so the old code would have taken the plant.
    $out = $this->probe($cwd, ';'.getenv('PATH'), 'repo', $env);
    self::assertSame(realpath($cwd), realpath($out['root'] ?? ''), json_encode($out));
    self::assertSame([], $this->markers($cwd));
})->onlyOnWindows();

test('an injected runner uses its own git and still receives the bare name', function () {
    $cwd = $this->dir('repo');
    $env = $this->plantExe($cwd);
    $this->initRepo($cwd);

    $out = $this->probe($cwd, $this->systemDir(), 'host', $env, [$this->realGit()]);
    self::assertSame(realpath($cwd), realpath($out['root'] ?? ''), json_encode($out));
    self::assertSame(['git'], $out['seen']);
    self::assertSame([], $this->markers($cwd));
})->onlyOnWindows();

test('success is cached and failure is not', function () {
    $cwd = $this->dir('cwd');

    $cached = $this->probe($cwd, (string) getenv('PATH'), 'cached');
    self::assertNotEmpty($cached['first'] ?? null, json_encode($cached));
    self::assertSame($cached['first'], $cached['second']);

    $failure = $this->probe($cwd, (string) getenv('PATH'), 'failure-not-cached');
    self::assertTrue($failure['failed'] ?? null, json_encode($failure));
    self::assertNotEmpty($failure['after'] ?? null);
})->onlyOnWindows();

test('a plant on a dot path entry is not searched on linux', function () {
    $this->posixPlantScenario();
})->onlyOnLinux();

test('a plant on a dot path entry is not searched on macos', function () {
    $this->posixPlantScenario();
})->onlyOnMac();

<?php

declare(strict_types=1);

namespace FancyGit\Tests;

use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

/**
 * A same-named executable in the working directory must never run in place of
 * git. Each scenario starts with a CONTROL that makes the old call in the same
 * environment and requires the plant to fire: without it, a machine that does
 * not search the cwd would pass every assertion here on vulnerable code. The
 * ambient NoDefaultCurrentDirectoryInExePath is exactly that, so it is removed
 * from the child's environment and the child reports that it is gone.
 */
final class PlantedGitTest extends TestCase
{
    private string $root;

    private static ?string $nodeCopy = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-git-plant-'.bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
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

    private function dir(string $name): string
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.$name;
        mkdir($dir);

        return $dir;
    }

    /** @return list<string> */
    private function markers(string $dir): array
    {
        return array_values(array_filter(scandir($dir), fn ($file) => str_ends_with($file, '.marker')));
    }

    private function clearMarkers(string $dir): void
    {
        foreach ($this->markers($dir) as $marker) {
            unlink($dir.DIRECTORY_SEPARATOR.$marker);
        }
    }

    /** Deliberately not the code under test. */
    private function realGit(): string
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
    private function probe(string $cwd, string $path, string $mode, array $extraEnv = [], array $args = []): array
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

    private function plantBatch(string $dir, string $name = 'git'): void
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
    private function plantExe(string $dir, string $name = 'git.exe'): array
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

    private function findNode(): string
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

    private function gitDir(): string
    {
        return dirname($this->realGit());
    }

    private function systemDir(): string
    {
        return getenv('SystemRoot').'\\System32';
    }

    private function phpDir(): string
    {
        return dirname(PHP_BINARY);
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_a_plant_on_dot_path_runs_under_a_bare_name_and_not_through_the_runner(): void
    {
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
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_a_plant_on_an_empty_path_entry_runs_under_a_bare_name_and_not_through_the_runner(): void
    {
        $cwd = $this->dir('cwd');
        $env = $this->plantExe($cwd);
        $path = ';'.$this->gitDir();

        self::assertSame(7, $this->probe($cwd, $path, 'bare', $env)['exit']);
        self::assertSame(['ran-git.exe.marker'], $this->markers($cwd));
        $this->clearMarkers($cwd);

        $fixed = $this->probe($cwd, $path, 'runner', $env);
        self::assertStringStartsWith('git version ', $fixed['stdout'] ?? json_encode($fixed));
        self::assertSame([], $this->markers($cwd));
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_a_plant_on_a_relative_path_entry_is_not_searched(): void
    {
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
    }

    /** The shape that fires with no PATH trickery: git is simply not installed where PATH says. */
    #[RequiresOperatingSystemFamily('Windows')]
    public function test_git_only_in_the_cwd_fails_closed(): void
    {
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
    }

    /** Symfony's own wrapper is a second lookup, so an absolute git alone does not close this. */
    #[RequiresOperatingSystemFamily('Windows')]
    public function test_a_planted_cmd_exe_does_not_run(): void
    {
        $cwd = $this->dir('cwd');
        $env = $this->plantExe($cwd, 'cmd.exe');

        foreach (['.;'.$this->gitDir().';'.$this->systemDir(), ';'.$this->gitDir().';'.$this->systemDir(), $this->gitDir()] as $path) {
            self::assertSame(7, $this->probe($cwd, $path, 'bare', $env)['exit'], $path);
            self::assertSame(['ran-cmd.exe.marker'], $this->markers($cwd), $path);
            $this->clearMarkers($cwd);

            $fixed = $this->probe($cwd, $path, 'runner', $env);
            self::assertStringStartsWith('git version ', $fixed['stdout'] ?? json_encode($fixed));
            self::assertSame([], $this->markers($cwd), $path);
        }
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_a_timed_out_command_does_not_run_a_planted_taskkill(): void
    {
        $cwd = $this->dir('cwd');
        $env = $this->plantExe($cwd, 'taskkill.exe');

        $out = $this->timeoutProbe($cwd, $env);
        self::assertTrue($out['cancelled'], json_encode($out));
        self::assertSame([], $this->markers($cwd));
    }

    /** @return array<string, mixed> */
    private function timeoutProbe(string $cwd, array $extraEnv): array
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

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_an_absolute_path_entry_is_found_identified_by_the_plant_it_runs_there(): void
    {
        $onPath = $this->dir('on-path');
        $cwd = $this->dir('cwd');
        $env = $this->plantExe($onPath);

        $out = $this->probe($cwd, $onPath.';'.$this->systemDir(), 'runner', $env);
        self::assertSame(7, $out['exit'] ?? null, json_encode($out));
        self::assertSame(['ran-git.exe.marker'], $this->markers($onPath));
        self::assertSame([], $this->markers($cwd));
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_a_batch_file_git_on_path_fails_clearly_and_does_not_run(): void
    {
        $onPath = $this->dir('on-path');
        $cwd = $this->dir('cwd');
        $this->plantBatch($onPath);

        $out = $this->probe($cwd, $onPath.';'.$this->systemDir(), 'runner');
        self::assertSame('not_found', $out['code'] ?? null, json_encode($out));
        self::assertStringContainsString('batch file', $out['message']);
        self::assertSame([], $this->markers($onPath));
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_a_normal_repository_with_a_plant_inside_it_is_read_with_real_git(): void
    {
        $cwd = $this->dir('repo');
        $env = $this->plantExe($cwd);
        $this->initRepo($cwd);

        // Empty entry first, so the old code would have taken the plant.
        $out = $this->probe($cwd, ';'.getenv('PATH'), 'repo', $env);
        self::assertSame(realpath($cwd), realpath($out['root'] ?? ''), json_encode($out));
        self::assertSame([], $this->markers($cwd));
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_an_injected_runner_uses_its_own_git_and_still_receives_the_bare_name(): void
    {
        $cwd = $this->dir('repo');
        $env = $this->plantExe($cwd);
        $this->initRepo($cwd);

        $out = $this->probe($cwd, $this->systemDir(), 'host', $env, [$this->realGit()]);
        self::assertSame(realpath($cwd), realpath($out['root'] ?? ''), json_encode($out));
        self::assertSame(['git'], $out['seen']);
        self::assertSame([], $this->markers($cwd));
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_success_is_cached_and_failure_is_not(): void
    {
        $cwd = $this->dir('cwd');

        $cached = $this->probe($cwd, (string) getenv('PATH'), 'cached');
        self::assertNotEmpty($cached['first'] ?? null, json_encode($cached));
        self::assertSame($cached['first'], $cached['second']);

        $failure = $this->probe($cwd, (string) getenv('PATH'), 'failure-not-cached');
        self::assertTrue($failure['failed'] ?? null, json_encode($failure));
        self::assertNotEmpty($failure['after'] ?? null);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_plant_on_a_dot_path_entry_is_not_searched_on_linux(): void
    {
        $this->posixPlantScenario();
    }

    #[RequiresOperatingSystemFamily('Darwin')]
    public function test_a_plant_on_a_dot_path_entry_is_not_searched_on_macos(): void
    {
        $this->posixPlantScenario();
    }

    private function posixPlantScenario(): void
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

    private function initRepo(string $dir): void
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

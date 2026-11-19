<?php

declare(strict_types=1);

// Run as a child of PlantedGitTest, so its cwd and environment can be hostile and
// its resolver cache is fresh. Prints one JSON line.

use FancyGit\GitRepository;
use FancyGit\Process\ProcessResult;
use FancyGit\Process\ProcessRunner;
use FancyGit\Process\SymfonyProcessRunner;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$out = ['ambient' => getenv('NoDefaultCurrentDirectoryInExePath')];

try {
    switch ($mode) {
        case 'bare':
            // The call the library used to make, as a control.
            $process = new Process(['git', 'version']);
            $process->run();
            $out += ['exit' => $process->getExitCode(), 'stdout' => $process->getOutput()];
            break;

        case 'vulnerable':
            // A deliberately vulnerable runner this suite owns, so a control does
            // not depend on how any library resolves names. proc_open with a bare,
            // relative executable name and no shell: Windows' CreateProcess then
            // searches the current directory (the ambient opt-out is removed from
            // this process's environment) and PATH, which is exactly the lookup
            // a planted executable exploits. The arguments are the executable name
            // followed by its arguments, e.g. `vulnerable cmd /D /C git version`.
            $command = array_slice($argv, 2);
            $process = proc_open(
                $command,
                [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
            if (! is_resource($process)) {
                throw new RuntimeException('proc_open failed for '.implode(' ', $command));
            }
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $out += ['exit' => proc_close($process), 'stdout' => $stdout];
            break;

        case 'runner':
            $out += ['stdout' => (new SymfonyProcessRunner)->run(['git', 'version'])->stdout];
            break;

        case 'repo':
            $info = (new GitRepository(getcwd()))->info();
            $out += ['root' => $info->root];
            break;

        case 'host':
            $host = new class($argv[2]) implements ProcessRunner
            {
                public array $seen = [];

                public function __construct(private string $git) {}

                public function run(array $command, ?float $timeout = null): ProcessResult
                {
                    $this->seen[] = $command[0];
                    $command[0] = $this->git;

                    return (new SymfonyProcessRunner)->run($command, $timeout);
                }
            };
            $info = (new GitRepository(getcwd(), $host))->info();
            $out += ['root' => $info->root, 'seen' => array_values(array_unique($host->seen))];
            break;

        case 'cached':
            $first = FancyGit\Process\ExecutableResolver::resolve('git');
            putenv('PATH=');
            $out += ['first' => $first, 'second' => FancyGit\Process\ExecutableResolver::resolve('git')];
            break;

        case 'failure-not-cached':
            $path = getenv('PATH');
            putenv('PATH=');
            try {
                FancyGit\Process\ExecutableResolver::resolve('git');
                $out += ['failed' => false];
            } catch (FancyGit\Error\GitException) {
                $out += ['failed' => true];
            }
            putenv('PATH='.$path);
            $out += ['after' => FancyGit\Process\ExecutableResolver::resolve('git')];
            break;

        default:
            throw new RuntimeException('unknown mode '.$mode);
    }
} catch (FancyGit\Error\GitException $e) {
    $out += ['code' => $e->errorCode->value, 'message' => $e->getMessage(), 'exit' => $e->getCode()];
}

echo json_encode($out);

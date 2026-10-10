<?php

namespace Venusian\Build\Targets;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Venusian\Build\Phar\PharBuilder;

/**
 * Runs the phar's boot check with the built binary, as the packaged app will
 * start, with HOME in a temporary directory removed afterwards, so the
 * developer's own data directory is never touched. A binary that cannot boot
 * the app stops the build with what it printed.
 */
final class BootCheck
{
    /** @param  Closure(list<string>, array<string, string>): array{0: int, 1: string, 2: string}  $exec  runs a command with extra environment: exit code, stdout, stderr */
    public function __construct(private readonly Closure $exec) {}

    /** @param  int  $timeout  seconds a boot may take before it counts as hung */
    public static function real(int $timeout = 120): self
    {
        return new self(static function (array $command, array $env) use ($timeout): array {
            $process = new Process($command, null, $env);
            $process->setTimeout($timeout);
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                return [124, $process->getOutput(), $process->getErrorOutput()."\nStill booting after {$timeout} s; stopped."];
            }

            return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
        });
    }

    public function check(string $binary, string $phar, string $name): void
    {
        $files = new Filesystem;
        $home = sys_get_temp_dir().'/venusian-boot-'.bin2hex(random_bytes(6));
        $files->mkdir($home);

        try {
            // The phar's stub runs a script inside itself only when named by its real path; any other name runs the sketch.
            $real = realpath($phar) ?: $phar;
            [$code, $out, $err] = ($this->exec)([$binary, "phar://{$real}/".PharBuilder::BOOT_CHECK], ['HOME' => $home, 'XDG_DATA_HOME' => "{$home}/.local/share"]);
        } finally {
            $files->remove($home);
        }

        $result = json_decode(trim((string) strrchr("\n".trim($out), "\n")), true);
        if ($code !== 0 || ! is_array($result) || ($result['booted'] ?? false) !== true) {
            throw new RuntimeException(self::failure($name, $err."\n".$out));
        }
    }

    /** The message both targets give: the app's name and the last 20 lines it printed. */
    public static function failure(string $name, string $said): string
    {
        return "{$name} does not start; the built binary, booting the packaged app, said:\n".implode("\n", array_slice(explode("\n", trim($said)), -20));
    }
}

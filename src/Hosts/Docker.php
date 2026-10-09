<?php

namespace Venusian\Build\Hosts;

use Closure;
use PharData;
use RuntimeException;
use Throwable;

/**
 * The docker CLI over a context: the local engine (the current context) or a
 * remote engine over ssh. A build host needs docker and nothing else; inputs
 * travel as a tar into a named volume, outputs come back one file at a time,
 * so a Mac drives an amd64 host and an arm64 host the same way.
 */
final class Docker
{
    /** @var array<string, string> context => architecture, asked once per context */
    private array $arches = [];

    /** @param  Closure(list<string>, ?string): string  $run  runs a command with an optional file on stdin, returns stdout, throws on failure */
    public function __construct(private readonly Closure $run) {}

    /** The current context: the local engine docker talks to without --context (desktop-linux under Docker Desktop, default elsewhere). */
    public function current(): string
    {
        return trim(($this->run)(['docker', 'context', 'show'], null));
    }

    public function arch(string $context): string
    {
        return $this->arches[$context] ??= $this->askArch($context);
    }

    private function askArch(string $context): string
    {
        try {
            $machine = trim(($this->run)(['docker', '--context', $context, 'info', '--format', '{{.Architecture}}'], null));
        } catch (Throwable $e) {
            throw new RuntimeException("docker context {$context} does not answer: {$e->getMessage()}");
        }

        if ($machine === '') {
            throw new RuntimeException("docker context {$context} does not answer: is its docker daemon running?");
        }

        return match ($machine) {
            'aarch64', 'arm64' => 'arm64',
            'x86_64', 'amd64' => 'x86_64',
            default => $machine,
        };
    }

    public function hasImage(string $context, string $image): bool
    {
        try {
            ($this->run)(['docker', '--context', $context, 'image', 'inspect', '--format', '{{.Id}}', $image], null);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function pull(string $context, string $image): bool
    {
        try {
            ($this->run)(['docker', '--context', $context, 'pull', '--quiet', $image], null);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Builds the image from a directory sent as a tar on stdin, so a remote engine needs no copy of it. */
    public function buildImage(string $context, string $image, string $dockerfile_dir): void
    {
        $tar = sys_get_temp_dir().'/venusian-context-'.bin2hex(random_bytes(6)).'.tar';
        (new PharData($tar))->buildFromDirectory($dockerfile_dir);

        try {
            ($this->run)(['docker', '--context', $context, 'build', '-t', $image, '-'], $tar);
        } finally {
            unlink($tar);
        }
    }

    public function push(string $context, string $image, string $volume, string $tar): void
    {
        ($this->run)(['docker', '--context', $context, 'run', '--rm', '-i', '-v', "{$volume}:/work", '-w', '/work', $image, 'tar', '-xf', '-'], $tar);
    }

    /** @param  list<string>  $args */
    public function run(string $context, string $image, string $volume, array $args): string
    {
        return ($this->run)(['docker', '--context', $context, 'run', '--rm', '-v', "{$volume}:/work", '-w', '/work', $image, ...$args], null);
    }

    public function fetch(string $context, string $image, string $volume, string $file, string $to): void
    {
        file_put_contents($to, ($this->run)(['docker', '--context', $context, 'run', '--rm', '-v', "{$volume}:/work", '-w', '/work', $image, 'cat', $file], null));
    }

    /** The real runner: symfony/process, no time limit (a first compile takes minutes), stdin from a file. */
    public static function real(): self
    {
        return new self(function (array $command, ?string $stdin): string {
            $process = new \Symfony\Component\Process\Process($command);
            $process->setTimeout(null);
            if ($stdin !== null) {
                $process->setInput(fopen($stdin, 'rb'));
            }
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: "{$command[0]} failed");
            }

            return $process->getOutput();
        });
    }
}

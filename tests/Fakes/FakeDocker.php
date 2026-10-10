<?php

namespace Venusian\Build\Tests\Fakes;

use Closure;
use PharData;
use RuntimeException;
use Venusian\Build\Hosts\Docker;

/**
 * A docker CLI that records every command: the current context is
 * desktop-linux; the jetson context is aarch64, every other x86_64; no image exists and pulls are denied; a pushed tar is
 * extracted to <root>/pushed-<n>; cat answers DEBFILE; a command `fail` answers with a message throws it; anything else "ok".
 */
final class FakeDocker
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var (Closure(list<string>): ?string)|null a command it answers with a message throws that message, after it is recorded */
    public ?Closure $fail = null;

    private int $pushes = 0;

    public function __construct(private readonly string $root) {}

    public function docker(): Docker
    {
        return new Docker($this->answer(...));
    }

    /** @param  list<string>  $command */
    private function answer(array $command, ?string $stdin): string
    {
        $this->commands[] = $command;

        if ($this->fail !== null && ($message = ($this->fail)($command)) !== null) {
            throw new RuntimeException($message);
        }

        return match (true) {
            $command[1] === 'context' => "desktop-linux\n",
            $command[3] === 'info' => $command[2] === 'jetson' ? "aarch64\n" : "x86_64\n",
            $command[3] === 'image' => throw new RuntimeException('No such image'),
            $command[3] === 'pull' => throw new RuntimeException('denied'),
            in_array('tar', $command, true) => $this->extract((string) $stdin),
            in_array('cat', $command, true) => 'DEBFILE',
            default => "ok\n",
        };
    }

    private function extract(string $tar): string
    {
        $dir = $this->root.'/pushed-'.++$this->pushes;
        mkdir($dir, 0777, true);
        (new PharData($tar))->extractTo($dir);

        return '';
    }
}

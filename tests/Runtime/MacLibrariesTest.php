<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\Runtime\MacLibraries;

/*
 * The static library prefix: named by libs.sh's hash, built once, reused
 * while complete, rebuilt when interrupted, replaced when the script changes.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-maclibs-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->script = $this->root.'/libs.sh';
    file_put_contents($this->script, "echo one\n");
    $this->commands = [];
    $this->run = function (array $command, ?string $cwd = null): string {
        $this->commands[] = $command;
        mkdir($command[2], 0777, true);
        touch($command[2].'/.complete');

        return '';
    };
    $this->report = fn (string $line) => null;
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('builds the prefix once, named by the script, and reuses it', function () {
    $libraries = new MacLibraries($this->root.'/macos', $this->run, $this->script);

    $first = $libraries->ensure($this->report);
    $second = $libraries->ensure($this->report);

    expect($first)->toBe($this->root.'/macos/prefix-14.0-'.substr(sha1("echo one\n"), 0, 12))
        ->and($second)->toBe($first)
        ->and($this->commands)->toBe([['sh', $this->script, $first]]);
});

it('builds a new prefix when the script changes and removes the old one', function () {
    $old = (new MacLibraries($this->root.'/macos', $this->run, $this->script))->ensure($this->report);
    file_put_contents($this->script, "echo two\n");

    $new = (new MacLibraries($this->root.'/macos', $this->run, $this->script))->ensure($this->report);

    expect($new)->not->toBe($old)
        ->and(is_dir($old))->toBeFalse()
        ->and(is_file($new.'/.complete'))->toBeTrue();
});

it('rebuilds a prefix an interrupted run left without .complete', function () {
    $libraries = new MacLibraries($this->root.'/macos', $this->run, $this->script);
    mkdir($libraries->path(), 0777, true);
    $this->run = function (array $command, ?string $cwd = null): string {
        $this->commands[] = $command;
        touch($command[2].'/.complete');

        return '';
    };

    (new MacLibraries($this->root.'/macos', $this->run, $this->script))->ensure($this->report);

    expect($this->commands)->toHaveCount(1);
});

it('fails when the script finishes without completing the prefix', function () {
    $nothing = fn (array $command, ?string $cwd = null): string => '';

    (new MacLibraries($this->root.'/macos', $nothing, $this->script))->ensure($this->report);
})->throws(RuntimeException::class, 'finished without writing');

it('holds a lock while building, so a second build waits instead of building over the first', function () {
    $locked = null;
    $root = $this->root.'/macos';
    $this->run = function (array $command, ?string $cwd = null) use (&$locked, $root): string {
        $handle = fopen($root.'/libs.lock', 'c');
        $locked = ! flock($handle, LOCK_EX | LOCK_NB);
        fclose($handle);
        mkdir($command[2], 0777, true);
        touch($command[2].'/.complete');

        return '';
    };

    (new MacLibraries($root, $this->run, $this->script))->ensure($this->report);

    expect($locked)->toBeTrue();
});

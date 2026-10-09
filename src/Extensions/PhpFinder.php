<?php

namespace Venusian\Build\Extensions;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Where the .so files the runtime loads come from: the PHP that runs venusian,
 * or the one build.json php names.
 *
 * A runtime built to match that PHP takes it as it is (running()). A runtime
 * that is always NTS (php-bin on macOS) needs NTS extensions (nts()): an NTS
 * PHP gives its own extension_dir; a ZTS one (zhp) gives the NTS directory
 * beside it, the same path without the -zts suffix, where an NTS build of the
 * same PHP installs its extensions.
 *
 * @phpstan-type Php array{binary: string, version: string, zts: bool, extension_dir: string}
 */
final class PhpFinder
{
    private readonly Closure $describe;

    /**
     * @param  string|null  $configured  build.json php, or null for the PHP running venusian
     * @param  Closure(string): (array{zts: bool, version: string, extension_dir: string}|null)|null  $describe
     */
    public function __construct(
        private readonly ?string $configured,
        ?Closure $describe = null,
        private readonly string $running = PHP_BINARY,
    ) {
        $this->describe = $describe ?? self::describeWithProcess(...);
    }

    /** @return Php the PHP as it is; version is major.minor */
    public function running(): array
    {
        $binary = $this->configured ?: $this->running;
        $facts = ($this->describe)($binary) ?? throw new RuntimeException("{$binary} is not a PHP binary");

        return [
            'binary' => $binary,
            'version' => implode('.', array_slice(explode('.', $facts['version']), 0, 2)),
            'zts' => (bool) $facts['zts'],
            'extension_dir' => rtrim($facts['extension_dir'], '/'),
        ];
    }

    /** @return Php an NTS PHP, or the NTS directory beside a ZTS one; zts is always false */
    public function nts(): array
    {
        $php = $this->running();

        if (! $php['zts']) {
            return $php;
        }

        if ($this->configured) {
            throw new RuntimeException("{$php['binary']} is ZTS; the runtime is NTS, so its extensions cannot load. Set php in build.json to an NTS PHP.");
        }

        $sibling = (string) preg_replace('/-zts$/', '', $php['extension_dir']);

        if ($sibling === $php['extension_dir'] || ! is_dir($sibling)) {
            throw new RuntimeException("{$php['binary']} is ZTS and has no NTS extension directory beside {$php['extension_dir']}. Install the extensions into an NTS PHP {$php['version']} and set php in build.json to it.");
        }

        return [...$php, 'zts' => false, 'extension_dir' => $sibling];
    }

    /** @return array{zts: bool, version: string, extension_dir: string}|null */
    private static function describeWithProcess(string $binary): ?array
    {
        $process = new Process([$binary, '-r', 'echo json_encode(["zts" => (bool) PHP_ZTS, "version" => PHP_VERSION, "extension_dir" => ini_get("extension_dir")]);']);
        $process->run();

        return $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
    }
}

<?php

namespace Venusian\Build\Extensions;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Where the .so files the runtime loads come from: the PHP that runs venusian.
 *
 * The runtime is NTS. When the running PHP is NTS its extension_dir is used
 * as it is. When it is ZTS (zhp), its extensions cannot load into the
 * runtime, so the NTS directory beside it is used: the same path without the
 * -zts suffix, where an NTS build of the same PHP installs its extensions.
 * build.php names another PHP outright.
 */
final class PhpFinder
{
    private readonly Closure $describe;

    /**
     * @param  string|null  $configured  build.php, or null for the PHP running venusian
     * @param  Closure(string): (array{zts: bool, version: string, extension_dir: string}|null)|null  $describe
     */
    public function __construct(
        private readonly ?string $configured,
        ?Closure $describe = null,
        private readonly string $running = PHP_BINARY,
    ) {
        $this->describe = $describe ?? self::describeWithProcess(...);
    }

    /** @return array{binary: string, version: string, extension_dir: string} version is major.minor */
    public function nts(): array
    {
        $binary = $this->configured ?: $this->running;
        $facts = ($this->describe)($binary) ?? throw new RuntimeException("{$binary} is not a PHP binary");
        $version = implode('.', array_slice(explode('.', $facts['version']), 0, 2));
        $directory = rtrim($facts['extension_dir'], '/');

        if (! $facts['zts']) {
            return ['binary' => $binary, 'version' => $version, 'extension_dir' => $directory];
        }

        if ($this->configured) {
            throw new RuntimeException("{$binary} is ZTS; the runtime is NTS, so its extensions cannot load. Set build.php to an NTS PHP.");
        }

        $sibling = (string) preg_replace('/-zts$/', '', $directory);

        if ($sibling === $directory || ! is_dir($sibling)) {
            throw new RuntimeException("{$binary} is ZTS and has no NTS extension directory beside {$directory}. Install the extensions into an NTS PHP {$version} and set build.php to it.");
        }

        return ['binary' => $binary, 'version' => $version, 'extension_dir' => $sibling];
    }

    /** @return array{zts: bool, version: string, extension_dir: string}|null */
    private static function describeWithProcess(string $binary): ?array
    {
        $process = new Process([$binary, '-r', 'echo json_encode(["zts" => (bool) PHP_ZTS, "version" => PHP_VERSION, "extension_dir" => ini_get("extension_dir")]);']);
        $process->run();

        return $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
    }
}

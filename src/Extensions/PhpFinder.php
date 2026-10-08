<?php

namespace Venusian\Build\Extensions;

use Closure;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The NTS PHP whose extension_dir supplies the .so files the runtime loads.
 *
 * The installer may run under a ZTS PHP; its extensions cannot load into
 * the NTS runtime, so the source PHP is chosen on its own.
 */
final class PhpFinder
{
    private const CANDIDATES = ['php', 'php8.4', 'php84'];

    private readonly Closure $describe;

    private readonly Closure $find;

    /**
     * @param  Closure(string): (array{zts: bool, version: string, extension_dir: string}|null)|null  $describe
     * @param  Closure(string): (string|null)|null  $find
     */
    public function __construct(private readonly ?string $configured, ?Closure $describe = null, ?Closure $find = null)
    {
        $this->describe = $describe ?? self::describeWithProcess(...);
        $this->find = $find ?? fn (string $name): ?string => (new ExecutableFinder)->find($name);
    }

    /** @return array{binary: string, version: string, extension_dir: string} version is major.minor */
    public function nts(): array
    {
        if ($this->configured) {
            $facts = ($this->describe)($this->configured) ?? throw new RuntimeException("{$this->configured} is not a PHP binary");

            if ($facts['zts']) {
                throw new RuntimeException("{$this->configured} is ZTS; the runtime is NTS, so its extensions cannot load. Set build.php to an NTS PHP.");
            }

            return $this->shape($this->configured, $facts);
        }

        foreach (self::CANDIDATES as $name) {
            $binary = ($this->find)($name);
            $facts = $binary ? ($this->describe)($binary) : null;

            if ($facts && ! $facts['zts']) {
                return $this->shape($binary, $facts);
            }
        }

        throw new RuntimeException('No NTS PHP found on PATH (looked for '.implode(', ', self::CANDIDATES).'). Set build.php.');
    }

    /**
     * @param  array{zts: bool, version: string, extension_dir: string}  $facts
     * @return array{binary: string, version: string, extension_dir: string}
     */
    private function shape(string $binary, array $facts): array
    {
        return [
            'binary' => $binary,
            'version' => implode('.', array_slice(explode('.', $facts['version']), 0, 2)),
            'extension_dir' => $facts['extension_dir'],
        ];
    }

    /** @return array{zts: bool, version: string, extension_dir: string}|null */
    private static function describeWithProcess(string $binary): ?array
    {
        $process = new Process([$binary, '-r', 'echo json_encode(["zts" => (bool) PHP_ZTS, "version" => PHP_VERSION, "extension_dir" => ini_get("extension_dir")]);']);
        $process->run();

        return $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
    }
}

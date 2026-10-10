<?php

namespace Venusian\Build\Runtime;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The static library set a macOS build links (build-env/macos/libs.sh),
 * built once per version of that script at the macOS floor and shared by
 * every app. The prefix is named by the script's hash, so new pins are a new
 * prefix; the script writes .complete last, so an interrupted build is
 * rebuilt rather than used. A lock in the root makes a second build wait for
 * the first rather than build the same prefix under it.
 */
final class MacLibraries
{
    public const FLOOR = '14.0';

    /**
     * @param  string  $root  ~/.venusian/build/macos
     * @param  Closure(list<string>, ?string): string  $run  runs a command, returning its output, throwing when it fails
     */
    public function __construct(
        private readonly string $root,
        private readonly Closure $run,
        private readonly string $script = __DIR__.'/../../build-env/macos/libs.sh',
    ) {}

    public function path(): string
    {
        return rtrim($this->root, '/').'/prefix-'.self::FLOOR.'-'.substr((string) sha1_file($this->script), 0, 12);
    }

    /**
     * @param  Closure(string): void  $report
     * @return string the complete prefix
     */
    public function ensure(Closure $report): string
    {
        $prefix = $this->path();
        $root = rtrim($this->root, '/');
        $files = new Filesystem;
        $files->mkdir($root);
        $lock = fopen("{$root}/libs.lock", 'c');

        if ($lock === false) {
            throw new RuntimeException("Cannot open {$root}/libs.lock.");
        }

        try {
            flock($lock, LOCK_EX);

            if (! is_file("{$prefix}/.complete")) {
                $report('Building the macOS library set for macOS '.self::FLOOR.' (once per set of pins; several minutes)');
                ($this->run)(['sh', $this->script, $prefix], null);

                if (! is_file("{$prefix}/.complete")) {
                    throw new RuntimeException("build-env/macos/libs.sh finished without writing {$prefix}/.complete.");
                }
            }

            foreach (glob("{$root}/prefix-*") ?: [] as $other) {
                if ($other !== $prefix) {
                    $files->remove($other);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $prefix;
    }
}

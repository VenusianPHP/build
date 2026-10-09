<?php

namespace Venusian\Build\Targets;

use Closure;
use Venusian\Build\App\Manifest;

/**
 * One platform a build produces. The phar is built once by Build; a target
 * turns it into something that installs and runs on that platform.
 */
interface Target
{
    /** macos-arm64, linux-arm64 or linux-x86_64 */
    public function name(): string;

    /** Whether the interview asks how to sign. */
    public function signs(): bool;

    /** Whether this machine, with the user's config, can build it. */
    public function available(): bool;

    /** One line saying why, when available() is false. */
    public function unavailableReason(): string;

    /**
     * @param  Closure(string): void  $report  one line per step
     * @return string path to the packaged app (a .app directory or a .deb file)
     */
    public function build(string $phar, Manifest $manifest, string $output_dir, Closure $report): string;
}

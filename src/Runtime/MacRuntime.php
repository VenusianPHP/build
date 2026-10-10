<?php

namespace Venusian\Build\Runtime;

use Closure;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Extensions\ExtensionSet;
use Venusian\Build\Sources\Sources;

/**
 * The Venusian SAPI binary for macos-arm64: PHP compiled natively by
 * build-env/macos/recipe.sh against the static library set, with the app's
 * extensions compiled in. Kept per app under <root>/<kebab>/<set hash>/; the
 * same set reuses it, a new set (extensions, commits, pins, PHP, SAPI)
 * replaces it.
 */
final class MacRuntime
{
    /**
     * @param  Closure(list<string>, ?string): string  $run  runs a command, returning its output, throwing when it fails
     * @param  string  $root  ~/.venusian/build/macos/runtimes
     */
    public function __construct(
        private readonly Sources $sources,
        private readonly MacLibraries $libraries,
        private readonly Closure $run,
        private readonly string $root,
        private readonly string $recipe = __DIR__.'/../../build-env/macos/recipe.sh',
        private readonly Filesystem $files = new Filesystem,
    ) {}

    /**
     * @param  Closure(string): void  $report
     * @return array{binary: string, prefix: string}
     */
    public function compile(Manifest $manifest, Closure $report): array
    {
        $sdk = trim(($this->run)(['xcrun', '--show-sdk-path'], null));
        // The Command Line Tools' SDK path names no version: an upgrade keeps the path and changes the SDK.
        $sdk_version = trim(($this->run)(['xcrun', '--show-sdk-version'], null));
        $directories = ['iconv' => "{$sdk}/usr", 'bz2' => "{$sdk}/usr", 'ldap' => $this->libraries->path()];
        [$names, $args, $packages] = (new ExtensionSet($this->sources, 'darwin', $directories))->resolve($manifest->extensions, $manifest->zts, $report);
        $prefix = $this->libraries->ensure($report);
        $php = $this->sources->phpSrc();
        $sapi = $this->sources->sapi();
        $hash = sha1(implode("\n", [basename($prefix), "sdk {$sdk_version}", (string) sha1_file($this->recipe), $php['version'], $sapi['version'], $manifest->zts ? 'zts' : 'nts', ...$args, ...array_map(fn (array $p): string => "{$p['name']}@{$p['version']}#{$p['reference']}", $packages)]));
        $dir = rtrim($this->root, '/').'/'.$manifest->kebab();
        $binary = "{$dir}/{$hash}/venusian";

        $report("Compiling PHP {$php['version']} ".($manifest->zts ? 'ZTS' : 'NTS')." with the Venusian SAPI {$sapi['version']} and ".implode(', ', $names));
        if ($packages !== []) {
            $report('From Packagist: '.implode(', ', array_map(fn (array $p): string => "{$p['name']} {$p['version']} (".substr($p['reference'], 0, 7).')', $packages)));
        }

        if (is_file($binary)) {
            $report("Reusing the runtime compiled for set {$hash}");

            return ['binary' => $binary, 'prefix' => $prefix];
        }

        $files = $this->files;
        $stage = sys_get_temp_dir().'/venusian-mac-'.bin2hex(random_bytes(6));
        $files->mkdir("{$stage}/in/ext");

        try {
            $files->copy($php['path'], "{$stage}/in/php-src.tar.xz");
            $files->copy($sapi['path'], "{$stage}/in/sapi.tar.gz");
            foreach ($packages as $package) {
                $files->copy($package['path'], "{$stage}/in/ext/{$package['name']}.zip");
            }
            file_put_contents("{$stage}/in/php.version", $php['version']);
            file_put_contents("{$stage}/in/configure.args", implode("\n", $args)."\n");
            file_put_contents("{$stage}/in/extensions.list", implode('', array_map(fn (array $p): string => "{$p['name']}\t{$p['build_path']}\n", $packages)));

            $out = ($this->run)(['sh', $this->recipe, $stage, $prefix], null);

            $files->remove(glob("{$dir}/*") ?: []);
            // Copied beside its name, then renamed: an interrupted copy never sits where is_file() reuses it.
            $files->copy("{$stage}/out/venusian", "{$binary}.part");
            $files->chmod("{$binary}.part", 0755);
            $files->rename("{$binary}.part", $binary);
            $report(trim($out) !== '' ? trim($out) : 'Compiled');
        } finally {
            $files->remove($stage);
        }

        return ['binary' => $binary, 'prefix' => $prefix];
    }
}

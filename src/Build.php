<?php

namespace Venusian\Build;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Extensions\ExtensionBundle;
use Venusian\Build\Extensions\PhpFinder;
use Venusian\Build\Phar\PharBuilder;
use Venusian\Build\Runtime\MicroCombiner;
use Venusian\Build\Runtime\MicroIni;
use Venusian\Build\Runtime\RuntimeStore;
use Venusian\Build\Targets\CodeSigner;
use Venusian\Build\Targets\MacAppBundle;

/**
 * One build, in order: NTS PHP, phar, runtime, extensions, combine, bundle, sign.
 */
final class Build
{
    /** First-party extensions a macOS app runs on when the PHP has them: the loop backend and HTTP on the loop. */
    private const PLATFORM_EXTENSIONS = ['kqueue', 'pcurl'];

    private readonly Closure $php_finder;

    /**
     * @param  Closure(?string): PhpFinder|null  $php_finder  builds the finder for a configured binary
     */
    public function __construct(
        private readonly RuntimeStore $runtimes,
        private readonly PharBuilder $phars,
        private readonly MicroCombiner $combiner,
        private readonly MacAppBundle $bundle,
        private readonly CodeSigner $signer,
        ?Closure $php_finder = null,
        private readonly string $os_family = PHP_OS_FAMILY,
    ) {
        $this->php_finder = $php_finder ?? fn (?string $configured): PhpFinder => new PhpFinder($configured);
    }

    /**
     * @param  Closure(string): void  $report  one line per step
     * @return string path to the .app
     */
    public function run(string $app_dir, Manifest $manifest, Closure $report): string
    {
        $this->checkTargets($manifest);

        $php = ($this->php_finder)($manifest->php)->nts();
        $report("Extensions from {$php['binary']} (PHP {$php['version']} NTS)");

        $files = new Filesystem;
        $work = sys_get_temp_dir().'/venusian-build-'.bin2hex(random_bytes(6));
        $files->mkdir($work);

        try {
            $report('Packing the phar');
            $phar = "{$work}/{$manifest->kebab()}.phar";
            $this->phars->build($app_dir, $manifest, $phar);

            $report("Runtime {$manifest->repository} for PHP {$php['version']}");
            $runtime = $this->runtimes->resolve($manifest->repository, 'mac', 'arm64', $php['version']);

            $extensions = (new ExtensionBundle($manifest->extensions, $runtime->extensions, $php['extension_dir'], self::PLATFORM_EXTENSIONS))->files();
            $report('Bundling '.($extensions === [] ? 'no extensions' : implode(', ', array_keys($extensions))));

            $sfx = "{$work}/micro.sfx";
            $files->copy($runtime->sfx, $sfx, true);
            $this->signer->signRuntime($sfx, $manifest->sign);

            $binary = "{$work}/{$manifest->kebab()}-bin";
            $this->combiner->combine($sfx, new MicroIni('lib', array_map('basename', $extensions)), $phar, $binary);

            $output = rtrim($app_dir, '/').'/build';
            $files->mkdir($output);
            $files->dumpFile("{$output}/.gitignore", "*\n");

            $report('Writing the bundle');
            $app = $this->bundle->write($output, $manifest, $binary, $extensions);

            $report($manifest->sign === 'adhoc' ? 'Signing ad hoc' : "Signing as {$manifest->sign}");
            $this->signer->sign($app, $manifest->sign, array_map(fn (string $path): string => "{$app}/Contents/MacOS/lib/".basename($path), $extensions));
        } finally {
            $files->remove($work);
        }

        return $app;
    }

    private function checkTargets(Manifest $manifest): void
    {
        foreach ($manifest->targets as $target) {
            if ($target !== 'macos-arm64') {
                throw new RuntimeException("Target {$target} is not built yet; macos-arm64 is the one this release builds.");
            }
        }

        if ($this->os_family !== 'Darwin') {
            throw new RuntimeException('macos-arm64 builds run on a Mac (codesign, sips, iconutil).');
        }
    }
}

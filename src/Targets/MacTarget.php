<?php

namespace Venusian\Build\Targets;

use Closure;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Extensions\ExtensionBundle;
use Venusian\Build\Extensions\PhpFinder;
use Venusian\Build\Runtime\MicroCombiner;
use Venusian\Build\Runtime\MicroIni;
use Venusian\Build\Runtime\RuntimeStore;

/** macos-arm64: php-bin's NTS micro.sfx, NTS .so files from the PHP running venusian, a signed .app. Builds only on a Mac. */
final class MacTarget implements Target
{
    /** First-party extensions shipped when the PHP has them: the loop backend and HTTP on the loop. */
    private const PLATFORM_EXTENSIONS = ['kqueue', 'pcurl'];

    private readonly Closure $php_finder;

    /** @param  Closure(?string): PhpFinder|null  $php_finder  builds the finder for a configured binary */
    public function __construct(
        private readonly RuntimeStore $runtimes,
        private readonly MicroCombiner $combiner,
        private readonly MacAppBundle $bundle,
        private readonly CodeSigner $signer,
        ?Closure $php_finder = null,
        private readonly string $os_family = PHP_OS_FAMILY,
    ) {
        $this->php_finder = $php_finder ?? fn (?string $configured): PhpFinder => new PhpFinder($configured);
    }

    public function name(): string { return 'macos-arm64'; }

    public function signs(): bool { return true; }

    public function available(): bool { return $this->os_family === 'Darwin'; }

    public function unavailableReason(): string { return 'macos-arm64 builds run on a Mac (codesign, sips, iconutil)'; }

    public function build(string $phar, Manifest $manifest, string $output_dir, Closure $report): string
    {
        $php = ($this->php_finder)($manifest->php)->nts();
        $report("Building macos-arm64; extensions from {$php['extension_dir']} (PHP {$php['version']})");

        $report("Runtime {$manifest->repository} for PHP {$php['version']}");
        $runtime = $this->runtimes->resolve($manifest->repository, 'mac', 'arm64', $php['version']);

        $extensions = (new ExtensionBundle($manifest->extensions, $runtime->extensions, $php['extension_dir'], self::PLATFORM_EXTENSIONS))->files();
        $report('Bundling '.($extensions === [] ? 'no extensions' : implode(', ', array_keys($extensions))));

        $files = new Filesystem;
        $work = dirname($phar);
        $sfx = "{$work}/micro.sfx";
        $files->copy($runtime->sfx, $sfx, true);
        $this->signer->signRuntime($sfx, $manifest->sign);

        $binary = "{$work}/{$manifest->kebab()}-bin";
        $this->combiner->combine($sfx, new MicroIni('lib', array_map('basename', array_values($extensions)), MicroIni::forBinary(basename($binary))), $phar, $binary);

        $report('Writing the bundle');
        $app = $this->bundle->write($output_dir, $manifest, $binary, $extensions);

        $report(CodeSigner::adhoc($manifest->sign) ? 'Signing ad hoc' : "Signing as {$manifest->sign}");
        $this->signer->sign($app, $manifest->sign, array_map(fn (string $path): string => "{$app}/Contents/MacOS/lib/".basename($path), array_values($extensions)));

        return $app;
    }
}

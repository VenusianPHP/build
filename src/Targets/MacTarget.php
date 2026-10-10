<?php

namespace Venusian\Build\Targets;

use Closure;
use RuntimeException;
use Venusian\Build\App\Manifest;
use Venusian\Build\Hosts\UserConfig;
use Venusian\Build\Runtime\MacRuntime;

/**
 * macos-arm64: PHP compiled natively with the app's extensions, <Name>.app
 * around the binary and the phar, signed under the hardened runtime, in a
 * .dmg; signed, notarized and stapled when this machine names a Developer ID
 * and a notarytool profile. Builds only on an Apple Silicon Mac. The app's
 * toolkit is AppKit; GTK and Qt run on a Mac from source for testing.
 */
final class MacTarget implements Target
{
    /** Command line tools a macOS build runs: the CLT's xcrun, and the build tools for the library set and buildconf. */
    private const TOOLS = ['xcrun', 'cmake', 'autoconf', 'pkg-config'];

    private readonly string $machine;

    /** @param  Closure(string): bool  $tool  whether a command is on PATH */
    public function __construct(
        private readonly MacRuntime $runtime,
        private readonly MacAppBundle $bundle,
        private readonly CodeSigner $signer,
        private readonly MacDiskImage $disk,
        private readonly BootCheck $boot,
        private readonly UserConfig $config,
        private readonly Closure $tool,
        private readonly string $os_family = PHP_OS_FAMILY,
        ?string $machine = null,
    ) {
        $this->machine = $machine ?? php_uname('m');
    }

    public function name(): string
    {
        return 'macos-arm64';
    }

    public function signs(): bool
    {
        return true;
    }

    public function available(): bool
    {
        return $this->appleSilicon() && $this->missing() === [];
    }

    public function unavailableReason(): string
    {
        if (! $this->appleSilicon()) {
            return 'macos-arm64 builds run on an Apple Silicon Mac';
        }

        return 'macos-arm64 builds need '.implode(', ', $this->missing()).' on PATH: xcode-select --install gives xcrun, brew install cmake autoconf pkg-config gives the rest (build tools only; nothing from Homebrew ships)';
    }

    public function build(string $phar, Manifest $manifest, string $output_dir, Closure $report): string
    {
        $toolkits = array_values(array_intersect(['gtk', 'qt'], $manifest->extensions));
        if ($toolkits !== [] && ! in_array('appkit', $manifest->extensions, true)) {
            throw new RuntimeException("macos-arm64 ships AppKit apps: {$manifest->name} requires ".implode(' and ', $toolkits).' but not appkit; require jovian/venusian-appkit (GTK and Qt run on a Mac from source for testing).');
        }

        $signing = $this->config->macos();
        $identity = $signing['sign'];
        // Before the compile: a certificate the keychain lacks fails now, not minutes in.
        $certificate = $this->signer->resolve($identity);
        $report('Building macos-arm64 for macOS 14 and newer');

        $runtime = $this->runtime->compile($manifest, $report);

        $report('Writing the bundle');
        $app = $this->bundle->write($output_dir, $manifest, $runtime['binary'], $phar, $runtime['prefix']);

        $report(CodeSigner::adhoc($identity) ? 'Signing ad hoc' : "Signing as {$identity}");
        $this->signer->sign($app, $certificate, $manifest->permissions);
        $report('Starting it once to check it boots');
        $this->boot->check("{$app}/Contents/MacOS/{$manifest->kebab()}", "{$app}/Contents/Resources/{$manifest->kebab()}.phar", $manifest->name);
        $report($app);

        $dmg = $this->disk->create($app, $manifest, $output_dir);

        if (CodeSigner::adhoc($identity)) {
            $report('Not notarized: signed ad hoc, so the .dmg opens on this Mac only. Name a Developer ID as macos.sign and a notarytool profile as macos.notary_profile in ~/.venusian/build/config.json to ship it.');

            return $dmg;
        }

        $this->disk->sign($dmg, $certificate);

        if ($signing['notary_profile'] === null) {
            $report('Not notarized: no macos.notary_profile in ~/.venusian/build/config.json, and Gatekeeper refuses a downloaded .dmg until Apple notarizes it. Create a profile with xcrun notarytool store-credentials <name>.');

            return $dmg;
        }

        $report("Notarizing with the profile {$signing['notary_profile']}; Apple usually answers within minutes");
        $this->disk->notarize($dmg, $signing['notary_profile']);
        $report('Notarized and stapled');

        return $dmg;
    }

    private function appleSilicon(): bool
    {
        return $this->os_family === 'Darwin' && in_array($this->machine, ['arm64', 'aarch64'], true);
    }

    /** @return list<string> */
    private function missing(): array
    {
        return array_values(array_filter(self::TOOLS, fn (string $tool): bool => ! ($this->tool)($tool)));
    }
}

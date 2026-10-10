<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Hosts\UserConfig;
use Venusian\Build\Runtime\MacLibraries;
use Venusian\Build\Runtime\MacRuntime;
use Venusian\Build\Targets\BootCheck;
use Venusian\Build\Targets\CodeSigner;
use Venusian\Build\Targets\MacAppBundle;
use Venusian\Build\Targets\MacDiskImage;
use Venusian\Build\Targets\MacTarget;
use Venusian\Build\Tests\Fakes\FakeMac;
use Venusian\Build\Tests\Fakes\FakeSources;

/*
 * macos-arm64 end to end over the fake Mac: available on Apple Silicon with
 * the tools; compile, bundle, sign, .dmg; notarized only with an identity
 * and a profile, and says so otherwise; AppKit or no toolkit.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-mactarget-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/build', 0777, true);
    file_put_contents($this->root.'/app.phar', 'PHAR');
    $this->mac = new FakeMac;
    $this->config = new UserConfig($this->root.'/home');
    $this->boots = [];
    $this->boot = [0, '{"booted":true,"database":null}', ''];
    $this->target = function (string $os = 'Darwin', string $machine = 'arm64', array $missing = []): MacTarget {
        $files = new Filesystem;
        $run = $this->mac->run();

        return new MacTarget(
            new MacRuntime(FakeSources::make($this->root.'/cache'), new MacLibraries($this->root.'/macos', $run), $run, $this->root.'/macos/runtimes'),
            new MacAppBundle($files, $run),
            new CodeSigner($run),
            new MacDiskImage($files, $this->mac->exec()),
            new BootCheck(function (array $command, array $env): array {
                $this->boots[] = [$command, $env, is_dir($env['HOME'])];

                return $this->boot;
            }),
            $this->config,
            fn (string $tool): bool => ! in_array($tool, $missing, true),
            $os,
            $machine,
        );
    };
    $this->manifest = Manifest::fromJson(json_encode(['name' => 'Star Gazer', 'id' => 'com.venusian.star-gazer', 'version' => '1.2.3', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => ['appkit'], 'targets' => ['macos-arm64'], 'base_path' => $this->root]));
    $this->lines = [];
    $this->report = function (string $line): void {
        $this->lines[] = $line;
    };
    $this->build = fn (?Manifest $manifest = null): string => ($this->target)()->build($this->root.'/app.phar', $manifest ?? $this->manifest, $this->root.'/build', $this->report);
    $this->notarytool = fn (): array => array_values(array_filter($this->mac->commands, fn (array $c): bool => $c[0] === 'xcrun' && $c[1] === 'notarytool'));
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('builds on Apple Silicon with the tools, and says what is missing otherwise', function () {
    expect(($this->target)()->available())->toBeTrue()
        ->and(($this->target)('Linux')->available())->toBeFalse()
        ->and(($this->target)('Linux')->unavailableReason())->toBe('macos-arm64 builds run on an Apple Silicon Mac')
        ->and(($this->target)('Darwin', 'x86_64')->available())->toBeFalse()
        ->and(($this->target)('Darwin', 'arm64', ['cmake', 'pkg-config'])->available())->toBeFalse()
        ->and(($this->target)('Darwin', 'arm64', ['cmake', 'pkg-config'])->unavailableReason())
        ->toBe('macos-arm64 builds need cmake, pkg-config on PATH: xcode-select --install gives xcrun, brew install cmake autoconf pkg-config gives the rest (build tools only; nothing from Homebrew ships)');
});

it('builds an ad hoc .app and .dmg and says it is not notarized', function () {
    $dmg = ($this->build)();

    expect($dmg)->toBe($this->root.'/build/star-gazer-1.2.3-macos-arm64.dmg')
        ->and(is_file($this->root.'/build/Star Gazer.app/Contents/MacOS/star-gazer'))->toBeTrue()
        ->and(file_get_contents($this->root.'/build/Star Gazer.app/Contents/Resources/star-gazer.phar'))->toBe('PHAR')
        ->and($this->lines)->toContain('Signing ad hoc')
        ->and($this->lines)->toContain('Not notarized: signed ad hoc, so the .dmg opens on this Mac only. Name a Developer ID as macos.sign and a notarytool profile as macos.notary_profile in ~/.venusian/build/config.json to ship it.')
        ->and(($this->notarytool)())->toBe([]);
});

it('signs, notarizes and staples with an identity and a profile', function () {
    $this->config->saveMacos('Developer ID Application: Me (TEAM)', 'venusian');

    $dmg = ($this->build)();

    expect(($this->notarytool)()[0])->toContain('submit', $dmg, 'venusian')
        ->and($this->mac->commands)->toContain(['codesign', '--force', '--timestamp', '--sign', '1111111111111111111111111111111111111111', $dmg])
        ->and($this->mac->commands)->toContain(['xcrun', 'stapler', 'staple', $dmg])
        ->and($this->lines)->toContain('Signing as Developer ID Application: Me (TEAM)')
        ->and($this->lines)->toContain('Notarized and stapled');
});

it('signs the image but says it is not notarized without a profile', function () {
    $this->config->saveMacos('Developer ID Application: Me (TEAM)', null);

    $dmg = ($this->build)();

    expect($this->mac->commands)->toContain(['codesign', '--force', '--timestamp', '--sign', '1111111111111111111111111111111111111111', $dmg])
        ->and(($this->notarytool)())->toBe([])
        ->and($this->lines)->toContain('Not notarized: no macos.notary_profile in ~/.venusian/build/config.json, and Gatekeeper refuses a downloaded .dmg until Apple notarizes it. Create a profile with xcrun notarytool store-credentials <name>.');
});

it('refuses GTK or Qt without AppKit', function () {
    ($this->build)($this->manifest->with(['extensions' => ['gtk']]));
})->throws(RuntimeException::class, 'macos-arm64 ships AppKit apps: Star Gazer requires gtk but not appkit; require jovian/venusian-appkit (GTK and Qt run on a Mac from source for testing).');

it('builds an app with AppKit and GTK, leaving GTK out', function () {
    ($this->build)($this->manifest->with(['extensions' => ['appkit', 'gtk']]));

    expect($this->lines)->toContain('Leaving out gtk: a macOS build ships AppKit; GTK and Qt run on a Mac from source for testing');
});

it('signs the Vulkan pair before the bundle', function () {
    $this->mac->vulkan = true;

    ($this->build)();
    $app = $this->root.'/build/Star Gazer.app';
    $signed = array_map(fn (array $c): string => (string) end($c), array_values(array_filter($this->mac->commands, fn (array $c): bool => $c[0] === 'codesign' && $c[1] === '--force')));

    expect(array_slice($signed, 0, 3))->toBe([$app.'/Contents/Frameworks/libMoltenVK.dylib', $app.'/Contents/Frameworks/libvulkan.1.dylib', $app]);
});

it('refuses an identity the keychain lacks before compiling', function () {
    $this->config->saveMacos('Developer ID Application: Gone (TEAM)', 'venusian');

    expect(fn () => ($this->build)())->toThrow(RuntimeException::class, 'No valid signing identity named "Developer ID Application: Gone (TEAM)"');
    expect(array_filter($this->mac->commands, fn (array $c): bool => $c[0] === 'sh'))->toBe([]);
});

it('boots the bundled app once with HOME in a directory it removes afterwards', function () {
    ($this->build)();
    $app = $this->root.'/build/Star Gazer.app';

    expect($this->boots)->toHaveCount(1)
        // The real path: the phar's stub runs a script inside itself only when named by the path it sees for itself (macOS /var is /private/var).
        ->and($this->boots[0][0])->toBe([$app.'/Contents/MacOS/star-gazer', 'phar://'.realpath($app.'/Contents/Resources/star-gazer.phar').'/.venusian-boot-check.php'])
        ->and($this->boots[0][2])->toBeTrue()
        ->and($this->boots[0][1]['HOME'])->toStartWith(sys_get_temp_dir())
        ->and(is_dir($this->boots[0][1]['HOME']))->toBeFalse()
        ->and($this->lines)->toContain('Starting it once to check it boots');
});

it('stops before the .dmg when the app does not boot, with what it said', function () {
    $this->boot = [255, '', "PHP Fatal error:  could not find driver\n"];

    expect(fn () => ($this->build)())->toThrow(RuntimeException::class, "Star Gazer does not start; the built binary, booting the packaged app, said:\nPHP Fatal error:  could not find driver");
    expect(array_filter($this->mac->commands, fn (array $c): bool => $c[0] === 'hdiutil'))->toBe([]);
});

<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Targets\CodeSigner;
use Venusian\Build\Targets\MacAppBundle;

/*
 * The .app wrapper: launcher that enters its own directory (the embedded
 * extension_dir is relative to the working directory), the binary, the
 * .so files, Info.plist, optional icon; then the signature.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-app-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    file_put_contents($this->root.'/stargazer-bin', 'BINARY');
    file_put_contents($this->root.'/appkit.so', 'SO');
    $this->commands = [];
    $this->run = function (array $command, ?string $cwd = null): void {
        $this->commands[] = $command;
    };
    $this->manifest = Manifest::fromJson('{"name":"Star Gazer","id":"com.venusian.star-gazer","version":"1.2.3","sketch":"stargazer","sketches":[],"icon":null,"extensions":[],"php":null,"repository":"phpacker/php-bin","sign":"adhoc","targets":["macos-arm64"],"base_path":"/app"}');
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('lays out the bundle with a launcher that enters its own directory', function () {
    $app = (new MacAppBundle(new Filesystem, $this->run))->write($this->root.'/build', $this->manifest, $this->root.'/stargazer-bin', ['appkit' => $this->root.'/appkit.so']);

    $plist = file_get_contents($app.'/Contents/Info.plist');

    expect($app)->toBe($this->root.'/build/Star Gazer.app')
        ->and(file_get_contents($app.'/Contents/MacOS/star-gazer-bin'))->toBe('BINARY')
        ->and(is_executable($app.'/Contents/MacOS/star-gazer-bin'))->toBeTrue()
        ->and(file_get_contents($app.'/Contents/MacOS/lib/appkit.so'))->toBe('SO')
        ->and(file_get_contents($app.'/Contents/MacOS/star-gazer'))->toBe("#!/bin/sh\ncd \"$(dirname \"$0\")\" && exec ./star-gazer-bin \"$@\"\n")
        ->and(is_executable($app.'/Contents/MacOS/star-gazer'))->toBeTrue()
        ->and($plist)->toContain('<string>com.venusian.star-gazer</string>')
        ->and($plist)->toContain("<key>CFBundleExecutable</key>\n    <string>star-gazer</string>")
        ->and($plist)->toContain("<key>CFBundleShortVersionString</key>\n    <string>1.2.3</string>")
        ->and($plist)->toContain("<key>CFBundleName</key>\n    <string>Star Gazer</string>")
        ->and($plist)->not->toContain('CFBundleIconFile')
        ->and($this->commands)->toBe([]);
});

it('replaces an earlier bundle of the same name', function () {
    $bundle = new MacAppBundle(new Filesystem, $this->run);
    $app = $bundle->write($this->root.'/build', $this->manifest, $this->root.'/stargazer-bin', []);
    file_put_contents($app.'/Contents/MacOS/lib/stale.so', 'OLD');

    $bundle->write($this->root.'/build', $this->manifest, $this->root.'/stargazer-bin', []);

    expect(is_file($app.'/Contents/MacOS/lib/stale.so'))->toBeFalse();
});

it('renders the icon through sips and iconutil when one is configured', function () {
    file_put_contents($this->root.'/icon.png', 'PNG');
    $manifest = $this->manifest->with(['icon' => 'icon.png', 'base_path' => $this->root]);

    $app = (new MacAppBundle(new Filesystem, $this->run))->write($this->root.'/build', $manifest, $this->root.'/stargazer-bin', []);

    $sips = array_values(array_filter($this->commands, fn (array $command): bool => $command[0] === 'sips'));
    $iconutil = array_values(array_filter($this->commands, fn (array $command): bool => $command[0] === 'iconutil'));

    expect(count($sips))->toBe(10)
        ->and($sips[0])->toContain($this->root.'/icon.png')
        ->and($iconutil)->toHaveCount(1)
        ->and($iconutil[0])->toContain('-o', $app.'/Contents/Resources/AppIcon.icns')
        ->and(file_get_contents($app.'/Contents/Info.plist'))->toContain("<key>CFBundleIconFile</key>\n    <string>AppIcon</string>");
});

it('fails when the configured icon is not there', function () {
    $manifest = $this->manifest->with(['icon' => 'missing.png', 'base_path' => $this->root]);

    (new MacAppBundle(new Filesystem, $this->run))->write($this->root.'/build', $manifest, $this->root.'/stargazer-bin', []);
})->throws(RuntimeException::class, 'missing.png');

it('signs ad hoc without --deep and leaves the runtime image alone', function () {
    $signer = new CodeSigner($this->run);
    $signer->signRuntime('/w/micro.sfx', 'adhoc');
    $signer->sign('/x/A.app', 'adhoc', ['/x/A.app/Contents/MacOS/lib/appkit.so']);

    expect($this->commands)->toBe([['codesign', '--force', '--sign', '-', '/x/A.app']]);
});

it('signs the runtime image, each library and the bundle with an identity, hardened, never --deep', function () {
    $signer = new CodeSigner($this->run);
    $signer->signRuntime('/w/micro.sfx', 'Developer ID Application: Me (TEAM)');
    $signer->sign('/x/A.app', 'Developer ID Application: Me (TEAM)', ['/x/A.app/Contents/MacOS/lib/appkit.so']);

    expect($this->commands)->toHaveCount(3)
        ->and($this->commands[0])->toContain('--options', 'runtime', '--entitlements', 'Developer ID Application: Me (TEAM)', '/w/micro.sfx')
        ->and($this->commands[1])->toBe(['codesign', '--force', '--sign', 'Developer ID Application: Me (TEAM)', '/x/A.app/Contents/MacOS/lib/appkit.so'])
        ->and($this->commands[2])->toContain('--options', 'runtime', '--entitlements', 'Developer ID Application: Me (TEAM)', '/x/A.app')
        ->and(implode(' ', array_merge(...$this->commands)))->not->toContain('--deep')
        ->and(CodeSigner::entitlements())->toContain('com.apple.security.cs.disable-library-validation');
});

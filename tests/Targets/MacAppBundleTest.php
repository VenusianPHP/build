<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Targets\CodeSigner;
use Venusian\Build\Targets\MacAppBundle;
use Venusian\Build\Tests\Fakes\FakeMac;

/*
 * The .app: the SAPI binary as Contents/MacOS/<kebab>, the phar in
 * Resources, the @rpath libraries in Frameworks with MoltenVK and its ICD
 * manifest when Vulkan is linked, Info.plist, the icon; then the signature.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-app-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/prefix/lib', 0777, true);
    mkdir($this->root.'/prefix/share/vulkan/icd.d', 0777, true);
    file_put_contents($this->root.'/prefix/lib/libvulkan.1.dylib', 'VULKAN');
    file_put_contents($this->root.'/prefix/lib/libMoltenVK.dylib', 'MOLTENVK');
    file_put_contents($this->root.'/prefix/share/vulkan/icd.d/MoltenVK_icd.json', '{"file_format_version": "1.0.0", "ICD": {"library_path": "../../../lib/libMoltenVK.dylib", "api_version": "1.4.0", "is_portability_driver": true}}');
    file_put_contents($this->root.'/venusian', 'BINARY');
    file_put_contents($this->root.'/app.phar', 'PHAR');
    $this->mac = new FakeMac;
    $this->bundle = fn (): MacAppBundle => new MacAppBundle(new Filesystem, $this->mac->run());
    $this->manifest = Manifest::fromJson(json_encode([
        'name' => 'Star Gazer', 'id' => 'com.venusian.star-gazer', 'version' => '1.2.3', 'build' => 7, 'sketch' => 'stargazer', 'sketches' => [],
        'icon' => null, 'extensions' => [], 'targets' => ['macos-arm64'], 'base_path' => $this->root, 'category' => 'Education',
    ]));
    $this->write = fn (?Manifest $manifest = null): string => ($this->bundle)()->write($this->root.'/build', $manifest ?? $this->manifest, $this->root.'/venusian', $this->root.'/app.phar', $this->root.'/prefix');
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('lays out the binary, the phar and Info.plist, with no Frameworks when nothing links through @rpath', function () {
    $app = ($this->write)();
    $plist = file_get_contents($app.'/Contents/Info.plist');

    expect($app)->toBe($this->root.'/build/Star Gazer.app')
        ->and(file_get_contents($app.'/Contents/MacOS/star-gazer'))->toBe('BINARY')
        ->and(is_executable($app.'/Contents/MacOS/star-gazer'))->toBeTrue()
        ->and(file_get_contents($app.'/Contents/Resources/star-gazer.phar'))->toBe('PHAR')
        ->and(is_dir($app.'/Contents/Frameworks'))->toBeFalse()
        ->and($plist)->toContain("<key>CFBundleIdentifier</key>\n    <string>com.venusian.star-gazer</string>")
        ->and($plist)->toContain("<key>CFBundleExecutable</key>\n    <string>star-gazer</string>")
        ->and($plist)->toContain("<key>CFBundleShortVersionString</key>\n    <string>1.2.3</string>")
        ->and($plist)->toContain("<key>CFBundleVersion</key>\n    <string>7</string>")
        ->and($plist)->toContain("<key>LSMinimumSystemVersion</key>\n    <string>14.0</string>")
        ->and($plist)->toContain("<key>LSApplicationCategoryType</key>\n    <string>public.app-category.education</string>")
        ->and($plist)->not->toContain('CFBundleIconFile')
        ->and($plist)->not->toContain('UsageDescription');
});

it('carries the Vulkan loader, MoltenVK and an ICD manifest pointing into Frameworks', function () {
    $this->mac->vulkan = true;

    $app = ($this->write)();
    $icd = json_decode(file_get_contents($app.'/Contents/Resources/vulkan/icd.d/MoltenVK_icd.json'), true);

    expect(file_get_contents($app.'/Contents/Frameworks/libvulkan.1.dylib'))->toBe('VULKAN')
        ->and(file_get_contents($app.'/Contents/Frameworks/libMoltenVK.dylib'))->toBe('MOLTENVK')
        ->and($icd['ICD']['library_path'])->toBe('../../../Frameworks/libMoltenVK.dylib')
        ->and($icd['ICD']['is_portability_driver'])->toBeTrue();
});

it('refuses a binary that links an @rpath library the set does not have', function () {
    $this->mac->vulkan = true;
    unlink($this->root.'/prefix/lib/libvulkan.1.dylib');

    ($this->write)();
})->throws(RuntimeException::class, 'The binary links @rpath/libvulkan.1.dylib, which the library set at');

it('writes each declared permission as its usage sentence', function () {
    $app = ($this->write)($this->manifest->with(['permissions' => ['camera' => 'Shows the sky through the webcam', 'bluetooth' => 'Talks to the telescope mount']]));
    $plist = file_get_contents($app.'/Contents/Info.plist');

    expect($plist)->toContain("<key>NSCameraUsageDescription</key>\n    <string>Shows the sky through the webcam</string>")
        ->and($plist)->toContain("<key>NSBluetoothAlwaysUsageDescription</key>\n    <string>Talks to the telescope mount</string>");
});

it('maps every freedesktop main category to an App Store category', function () {
    expect(array_keys(MacAppBundle::CATEGORIES))->toBe(Venusian\Build\App\BuildJson::CATEGORIES)
        ->and(array_keys(MacAppBundle::PERMISSIONS))->toBe(Venusian\Build\App\BuildJson::PERMISSIONS);
});

it('replaces an earlier bundle of the same name', function () {
    $app = ($this->write)();
    file_put_contents($app.'/Contents/Resources/stale', 'OLD');

    ($this->write)();

    expect(is_file($app.'/Contents/Resources/stale'))->toBeFalse();
});

it('renders the icon through sips and iconutil when one is configured', function () {
    file_put_contents($this->root.'/icon.png', 'PNG');

    $app = ($this->write)($this->manifest->with(['icon' => 'icon.png']));
    $sips = array_values(array_filter($this->mac->commands, fn (array $c): bool => $c[0] === 'sips'));
    $iconutil = array_values(array_filter($this->mac->commands, fn (array $c): bool => $c[0] === 'iconutil'));

    expect($sips)->toHaveCount(10)
        ->and($sips[0])->toContain($this->root.'/icon.png')
        ->and($iconutil)->toHaveCount(1)
        ->and($iconutil[0])->toContain('-o', $app.'/Contents/Resources/AppIcon.icns')
        ->and(file_get_contents($app.'/Contents/Info.plist'))->toContain("<key>CFBundleIconFile</key>\n    <string>AppIcon</string>");
});

it('fails when the configured icon is not there', function () {
    ($this->write)($this->manifest->with(['icon' => 'missing.png']));
})->throws(RuntimeException::class, 'missing.png');

it('signs ad hoc: each dylib, then the bundle with entitlements, hardened, then a strict check', function () {
    $this->mac->vulkan = true;
    $app = ($this->write)();
    $this->mac->commands = [];

    (new CodeSigner($this->mac->run()))->sign($app, 'adhoc');
    $codesign = $this->mac->commands;

    expect($codesign)->toHaveCount(4)
        ->and($codesign[0])->toBe(['codesign', '--force', '--options', 'runtime', '--sign', '-', $app.'/Contents/Frameworks/libMoltenVK.dylib'])
        ->and($codesign[1])->toBe(['codesign', '--force', '--options', 'runtime', '--sign', '-', $app.'/Contents/Frameworks/libvulkan.1.dylib'])
        ->and(array_slice($codesign[2], 0, 6))->toBe(['codesign', '--force', '--options', 'runtime', '--sign', '-'])
        ->and($codesign[2])->toContain('--entitlements', $app)
        ->and($codesign[3])->toBe(['codesign', '--verify', '--strict', '--deep', '--verbose=2', $app])
        ->and(implode(' ', $codesign[2]))->not->toContain('--deep');
});

it('signs with an identity and a secure timestamp', function () {
    $app = ($this->write)();
    $this->mac->commands = [];

    (new CodeSigner($this->mac->run()))->sign($app, 'Developer ID Application: Me (TEAM)');

    expect($this->mac->commands[0])->toContain('--timestamp', '--sign', 'Developer ID Application: Me (TEAM)', '--entitlements', $app);
});

it('grants JIT always, library validation off only ad hoc, and the device entitlement of each permission', function () {
    $adhoc = CodeSigner::entitlements([], true);
    $identity = CodeSigner::entitlements(['camera' => 'x', 'microphone' => 'y', 'bluetooth' => 'z'], false);

    expect($adhoc)->toContain('com.apple.security.cs.allow-jit')
        ->and($adhoc)->toContain('com.apple.security.cs.disable-library-validation')
        ->and($identity)->toContain('com.apple.security.cs.allow-jit')
        ->and($identity)->not->toContain('disable-library-validation')
        ->and($identity)->toContain('com.apple.security.device.camera')
        ->and($identity)->toContain('com.apple.security.device.audio-input');
});

it('leaves no entitlements file behind', function () {
    $app = ($this->write)();
    $before = glob(sys_get_temp_dir().'/venusian-entitlements*') ?: [];

    (new CodeSigner($this->mac->run()))->sign($app, 'adhoc');

    expect(glob(sys_get_temp_dir().'/venusian-entitlements*') ?: [])->toBe($before);
});

it('signs a Developer ID by its certificate hash, and passes a hash through', function () {
    $signer = new CodeSigner($this->mac->run());

    expect($signer->resolve('Developer ID Application: Me (TEAM)'))->toBe('1111111111111111111111111111111111111111')
        ->and($signer->resolve('adhoc'))->toBe('adhoc');
    $this->mac->commands = [];
    expect($signer->resolve('98FB1CAD51B5EB00D3E6144C70ECBD10DD397208'))->toBe('98FB1CAD51B5EB00D3E6144C70ECBD10DD397208')
        ->and($this->mac->commands)->toBe([]);
});

it('picks the certificate that expires last when two valid identities share a name', function () {
    $name = 'Developer ID Application: Me (TEAM)';
    $certificate = function (int $days) use ($name): array {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']), null, $key, $days, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $pem);

        return [strtoupper(openssl_x509_fingerprint($x509, 'sha1')), $pem];
    };
    [$old, $old_pem] = $certificate(30);
    [$new, $new_pem] = $certificate(1800);
    $this->mac->identities = "  1) {$old} \"{$name}\"\n  2) {$new} \"{$name}\"\n     2 valid identities found\n";
    $this->mac->certificates = "SHA-256 hash: AA\nSHA-1 hash: {$old}\n{$old_pem}SHA-256 hash: BB\nSHA-1 hash: {$new}\n{$new_pem}";

    expect((new CodeSigner($this->mac->run()))->resolve($name))->toBe($new);
});

it('refuses an identity the keychain holds no valid certificate for', function () {
    $this->mac->identities = "     0 valid identities found\n";

    (new CodeSigner($this->mac->run()))->resolve('Developer ID Application: Me (TEAM)');
})->throws(RuntimeException::class, 'No valid signing identity named "Developer ID Application: Me (TEAM)" in the keychain');

<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Targets\MacDiskImage;
use Venusian\Build\Tests\Fakes\FakeMac;

/*
 * The .dmg: the .app beside an Applications link, compressed; signed with an
 * identity; sent to the notary service, stapled and assessed; a rejection
 * reports Apple's issues.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-dmg-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/build/Star Gazer.app/Contents', 0777, true);
    $this->mac = new FakeMac;
    $this->manifest = Manifest::fromJson(json_encode(['name' => 'Star Gazer', 'id' => 'com.venusian.star-gazer', 'version' => '1.2.3', 'sketch' => 's', 'sketches' => [], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root]));
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('copies the .app beside an Applications link into an image of a size it chooses, then compresses it', function () {
    $linked = null;
    $mac = $this->mac;
    $exec = function (array $command, ?string $cwd = null) use ($mac, &$linked): array {
        if ($command[0] === 'hdiutil' && $command[1] === 'detach') {
            $linked = readlink($command[2].'/Applications');
        }

        return ($mac->exec())($command, $cwd);
    };

    $dmg = (new MacDiskImage(new Filesystem, $exec))->create($this->root.'/build/Star Gazer.app', $this->manifest, $this->root.'/build');
    $work = $this->root.'/build/.dmg-star-gazer';

    expect($dmg)->toBe($this->root.'/build/star-gazer-1.2.3-macos-arm64.dmg')
        ->and(file_get_contents($dmg))->toBe('DMG')
        ->and($this->mac->commands)->toBe([
            ['hdiutil', 'create', '-size', '16m', '-fs', 'HFS+', '-volname', 'Star Gazer', '-ov', $work.'/rw.dmg'],
            ['hdiutil', 'attach', $work.'/rw.dmg', '-nobrowse', '-noautoopen', '-mountpoint', $work.'/mnt'],
            ['ditto', $this->root.'/build/Star Gazer.app', $work.'/mnt/Star Gazer.app'],
            ['hdiutil', 'detach', $work.'/mnt'],
            ['hdiutil', 'convert', $work.'/rw.dmg', '-format', 'UDZO', '-ov', '-o', $dmg],
        ])
        ->and($linked)->toBe('/Applications')
        ->and(is_dir($work))->toBeFalse();
});

it('sizes the image a quarter over the app, plus 16 MB', function () {
    file_put_contents($this->root.'/build/Star Gazer.app/Contents/big', str_repeat('x', 8 * 1_048_576));

    (new MacDiskImage(new Filesystem, $this->mac->exec()))->create($this->root.'/build/Star Gazer.app', $this->manifest, $this->root.'/build');

    expect($this->mac->commands[0][3])->toBe('26m');
});

it('detaches the image when copying into it fails', function () {
    $mac = $this->mac;
    $exec = fn (array $command, ?string $cwd = null): array => $command[0] === 'ditto' ? [1, '', 'ditto: No space left on device'] : ($mac->exec())($command, $cwd);

    expect(fn () => (new MacDiskImage(new Filesystem, $exec))->create($this->root.'/build/Star Gazer.app', $this->manifest, $this->root.'/build'))
        ->toThrow(RuntimeException::class, 'ditto: No space left on device');
    expect(array_column($this->mac->commands, 1))->toContain('detach')
        ->and(is_dir($this->root.'/build/.dmg-star-gazer'))->toBeFalse();
});

it('notarizes, staples and assesses an accepted image', function () {
    (new MacDiskImage(new Filesystem, $this->mac->exec()))->notarize('/b/s.dmg', 'venusian');

    expect($this->mac->commands)->toBe([
        ['xcrun', 'notarytool', 'submit', '/b/s.dmg', '--keychain-profile', 'venusian', '--wait', '--output-format', 'json'],
        ['xcrun', 'stapler', 'staple', '/b/s.dmg'],
        ['spctl', '--assess', '--type', 'open', '--context', 'context:primary-signature', '--verbose', '/b/s.dmg'],
    ]);
});

it('reports Apple\'s issues when the image is rejected', function () {
    $this->mac->notary = '{"id":"n-2","status":"Invalid"}';
    $this->mac->notary_log = '{"issues":[{"path":"s.dmg/Star Gazer.app/Contents/MacOS/star-gazer","message":"The binary is not signed with a valid Developer ID certificate."}]}';

    expect(fn () => (new MacDiskImage(new Filesystem, $this->mac->exec()))->notarize('/b/s.dmg', 'venusian'))
        ->toThrow(RuntimeException::class, "Apple did not notarize s.dmg (Invalid):\ns.dmg/Star Gazer.app/Contents/MacOS/star-gazer: The binary is not signed with a valid Developer ID certificate.");
});

it('says when notarytool gives no answer', function () {
    $this->mac->notary = 'Error: No Keychain password item found for profile: venusian';

    expect(fn () => (new MacDiskImage(new Filesystem, $this->mac->exec()))->notarize('/b/s.dmg', 'venusian'))
        ->toThrow(RuntimeException::class, 'notarytool gave no result for s.dmg: Error: No Keychain password item found for profile: venusian');
});

it('signs the image with the identity and a timestamp', function () {
    (new MacDiskImage(new Filesystem, $this->mac->exec()))->sign('/b/s.dmg', 'Developer ID Application: Me (TEAM)');

    expect($this->mac->commands)->toBe([['codesign', '--force', '--timestamp', '--sign', 'Developer ID Application: Me (TEAM)', '/b/s.dmg']]);
});

it('names the command that failed', function () {
    $exec = fn (array $command, ?string $cwd = null): array => [1, '', 'hdiutil: create failed - Resource busy'];

    expect(fn () => (new MacDiskImage(new Filesystem, $exec))->create($this->root.'/build/Star Gazer.app', $this->manifest, $this->root.'/build'))
        ->toThrow(RuntimeException::class, 'hdiutil create -size');
});

it('detaches a mount an interrupted run left before starting over', function () {
    $work = $this->root.'/build/.dmg-star-gazer';
    mkdir($work.'/mnt', 0777, true);

    (new MacDiskImage(new Filesystem, $this->mac->exec()))->create($this->root.'/build/Star Gazer.app', $this->manifest, $this->root.'/build');

    expect($this->mac->commands[0])->toBe(['hdiutil', 'detach', '-force', $work.'/mnt']);
});

it('reports the copy failure even when the image will not detach', function () {
    $mac = $this->mac;
    $exec = fn (array $command, ?string $cwd = null): array => match (true) {
        $command[0] === 'ditto' => [1, '', 'ditto: No space left on device'],
        $command[0] === 'hdiutil' && $command[1] === 'detach' => [16, '', 'hdiutil: detach failed - Resource busy'],
        default => ($mac->exec())($command, $cwd),
    };

    expect(fn () => (new MacDiskImage(new Filesystem, $exec))->create($this->root.'/build/Star Gazer.app', $this->manifest, $this->root.'/build'))
        ->toThrow(RuntimeException::class, 'ditto: No space left on device');
});

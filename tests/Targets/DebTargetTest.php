<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Hosts\Docker;
use Venusian\Build\Sources\NotFound;
use Venusian\Build\Sources\Sources;
use Venusian\Build\Targets\DebTarget;
use Venusian\Build\Tests\Fakes\FakeDocker;
use Venusian\Build\Tests\Fakes\FakeSources;

/*
 * DebTarget: pick the docker context for the architecture, make sure the
 * build image is there, push the sources, the phar and the recipe files
 * into the app's volume, compile (cached per set), package, fetch the .deb.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-deb-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/art', 0777, true);
    mkdir($this->root.'/build');
    mkdir($this->root.'/env');
    file_put_contents($this->root.'/env/Dockerfile', 'FROM ubuntu:24.04');
    file_put_contents($this->root.'/env/recipe.sh', '#!/bin/sh');
    file_put_contents($this->root.'/env/package.sh', '#!/bin/sh');
    file_put_contents($this->root.'/env/apt-build.sh', '#!/bin/sh');
    file_put_contents($this->root.'/app.phar', 'PHAR');
    // A 64x64 PNG: getimagesize reads the IHDR; the pixels need not be real.
    file_put_contents($this->root.'/app/art/icon.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAC0lEQVR4nGNgGAUAAAEAAVYYRdAAAAAASUVORK5CYII='));

    $this->sources = FakeSources::make($this->root.'/cache');
    $this->fake = new FakeDocker($this->root);
    $this->docker = $this->fake->docker();

    $this->manifest = Manifest::fromJson(json_encode([
        'name' => 'Star Gazer', 'id' => 'com.venusian.stargazer', 'version' => '1.2.0', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
        'icon' => 'art/icon.png', 'extensions' => ['ctype', 'gtk', 'mbstring', 'pcurl', 'dom'],
        'targets' => ['linux-x86_64'], 'base_path' => $this->root.'/app', 'summary' => 'The sky, daily', 'description' => "NASA's picture of the day.\n\nAnd more.",
        'author' => 'Angel <a@b.c>', 'homepage' => 'https://venusian.dev', 'license' => 'MIT', 'category' => 'Education', 'windowed' => true,
    ]));
    $this->lines = [];
    $this->report = function (string $line): void { $this->lines[] = $line; };
    // The image tag carries the Dockerfile's hash, so a changed Dockerfile builds a new image on every host.
    $this->image = DebTarget::IMAGE.'-'.substr(sha1('FROM ubuntu:24.04'), 0, 12);
    $this->target = fn (string $arch, array $hosts): DebTarget => new DebTarget($arch, $this->sources, $this->docker, $hosts, $this->root.'/env');
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('is available with a context whose CPU matches, and says why not otherwise', function () {
    expect(($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->available())->toBeTrue()
        ->and(($this->target)('arm64', ['linux-arm64' => 'jetson'])->available())->toBeTrue()
        ->and(($this->target)('arm64', [])->available())->toBeFalse()
        ->and(($this->target)('x86_64', [])->available())->toBeTrue()
        ->and(($this->target)('arm64', [])->unavailableReason())->toBe('no docker host for linux-arm64: name a context under hosts in ~/.venusian/build/config.json, or run on an arm64 machine with docker')
        ->and(($this->target)('x86_64', ['linux-x86_64' => 'jetson'])->available())->toBeFalse()
        ->and(($this->target)('x86_64', ['linux-x86_64' => 'jetson'])->unavailableReason())->toBe('docker context jetson is arm64, not x86_64');
});

it('builds the image locally when the registry has none, pushes everything, compiles, packages and fetches the .deb', function () {
    $deb = ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);

    expect($deb)->toBe($this->root.'/build/star-gazer_1.2.0_amd64.deb')
        ->and(file_get_contents($deb))->toBe('DEBFILE');

    $kinds = array_map(fn (array $c): string => $c[3].(isset($c[4]) && $c[4] === '-t' ? ' build' : ''), $this->fake->commands);
    expect($kinds)->toBe(['info', 'image', 'pull', 'build build', 'run', 'run', 'run', 'run', 'run', 'run'])
        ->and($this->fake->commands[4])->toBe(['docker', '--context', 'gamingpc', 'run', '--rm', '-v', 'venusian-build-star-gazer:/work', '-w', '/work', $this->image, 'rm', '-rf', 'in', 'out', 'pkg']);

    $in = $this->root.'/pushed-1/in';
    expect(file_get_contents($in.'/php.version'))->toBe('8.4.26')
        ->and(file_get_contents($in.'/extensions.list'))->toBe("epoll\t.\npcurl\text\nrasterize\t.\ngtk\t.\n")
        ->and(file_get_contents($in.'/configure.args'))->toBe(implode("\n", [
            '--disable-all', '--disable-cli', '--disable-cgi', '--disable-phpdbg', '--disable-rpath', '--enable-venusian', '--enable-phar',
            '--enable-ctype', '--enable-filter', '--enable-mbstring', '--with-openssl', '--enable-pdo', '--enable-epoll', '--enable-pcurl', '--enable-rasterize',
            '--enable-gtk', '--enable-dom', '--enable-sockets', '--with-curl', '--with-libxml',
        ])."\n")
        ->and(is_file($in.'/php-src.tar.xz'))->toBeTrue()
        ->and(is_file($in.'/sapi.tar.gz'))->toBeTrue()
        ->and(is_file($in.'/ext/gtk.zip'))->toBeTrue()
        ->and(is_file($in.'/ext/epoll.zip'))->toBeTrue()
        ->and(file_get_contents($in.'/deb/app.phar'))->toBe('PHAR')
        ->and(file_get_contents($in.'/deb/kebab'))->toBe('star-gazer')
        ->and(file_get_contents($in.'/deb/arch'))->toBe('amd64')
        ->and(file_get_contents($in.'/deb/icon.size'))->toBe('64')
        ->and(is_file($this->root.'/pushed-1/recipe.sh'))->toBeTrue()
        ->and(is_file($this->root.'/pushed-1/apt-build.sh'))->toBeTrue()
        ->and(file_get_contents($in.'/deb/control'))->toBe(implode("\n", [
            'Package: star-gazer', 'Version: 1.2.0', 'Architecture: amd64', 'Maintainer: Angel <a@b.c>', 'Section: misc', 'Priority: optional',
            'Homepage: https://venusian.dev', 'Recommends: libgtk-4-media-gstreamer', 'Description: The sky, daily', " NASA's picture of the day.", ' .', ' And more.',
        ])."\n")
        ->and(file_get_contents($in.'/deb/desktop'))->toBe(implode("\n", [
            '[Desktop Entry]', 'Type=Application', 'Name=Star Gazer', 'Comment=The sky, daily', 'Exec=/usr/bin/star-gazer', 'Icon=com.venusian.stargazer',
            'Terminal=false', 'Categories=Education;', 'StartupNotify=true', 'StartupWMClass=com.venusian.stargazer',
        ])."\n")
        ->and(file_get_contents($in.'/deb/metainfo.xml'))->toContain('<id>com.venusian.stargazer</id>')
        ->and(file_get_contents($in.'/deb/metainfo.xml'))->toContain('<launchable type="desktop-id">com.venusian.stargazer.desktop</launchable>')
        ->and(file_get_contents($in.'/deb/metainfo.xml'))->toContain('<project_license>MIT</project_license>')
        ->and(file_get_contents($in.'/deb/copyright'))->toContain('License: MIT')
        ->and($this->fake->commands[6])->toBe(['docker', '--context', 'gamingpc', 'run', '--rm', '-v', 'venusian-build-star-gazer:/work', '-w', '/work', $this->image, 'sh', 'recipe.sh'])
        ->and($this->fake->commands[7])->toBe(['docker', '--context', 'gamingpc', 'run', '--rm', '-v', 'venusian-build-star-gazer:/work', '-w', '/work', $this->image, 'sh', '-c', 'mkdir -p /tmp/boot/bin && cp out/venusian /tmp/boot/bin/venusian && cp in/deb/app.phar /tmp/boot/bin/venusian.phar && HOME=/tmp/boot XDG_DATA_HOME=/tmp/boot/.local/share /tmp/boot/bin/venusian phar:///tmp/boot/bin/venusian.phar/.venusian-boot-check.php'])
        ->and($this->lines)->toContain('Starting it once to check it boots')
        ->and($this->lines)->toContain('Building linux-x86_64 on docker context gamingpc')
        ->and($this->lines)->toContain('Compiling PHP 8.4.26 NTS with the Venusian SAPI v0.10.2 and ctype, filter, mbstring, openssl, pdo, epoll, pcurl, rasterize, gtk, dom, sockets, curl, libxml')
        ->and($this->lines)->toContain('From Packagist: epoll v0.10.0 (ref-epo), pcurl v0.10.0 (ref-pcu), rasterize v0.10.0 (ref-ras), gtk v0.10.0 (ref-gtk)');
});

it('hashes the set from the PHP version, SAPI tag, thread safety, flags and extension versions, the same twice', function () {
    $target = ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc']);
    $target->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);
    $target->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);

    expect(file_get_contents($this->root.'/pushed-1/in/set.hash'))->toBe(file_get_contents($this->root.'/pushed-2/in/set.hash'))
        ->and(file_get_contents($this->root.'/pushed-1/in/set.hash'))->toMatch('/^[0-9a-f]{40}$/');

    $zts = ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc']);
    $zts->build($this->root.'/app.phar', $this->manifest->with(['zts' => true]), $this->root.'/build', $this->report);
    expect(file_get_contents($this->root.'/pushed-3/in/set.hash'))->not->toBe(file_get_contents($this->root.'/pushed-1/in/set.hash'))
        ->and(file_get_contents($this->root.'/pushed-3/in/configure.args'))->toContain("--enable-zts\n");
});

it('builds on the current context when no host is named and its CPU matches', function () {
    ($this->target)('x86_64', [])->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);

    expect($this->lines)->toContain('Building linux-x86_64 on docker context desktop-linux')
        ->and($this->fake->commands[1])->toBe(['docker', '--context', 'desktop-linux', 'info', '--format', '{{.Architecture}}']);
});

it('leaves out the desktop entry, icon and metainfo for an app without a toolkit', function () {
    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest->with(['windowed' => false, 'icon' => null]), $this->root.'/build', $this->report);

    expect(is_file($this->root.'/pushed-1/in/deb/desktop'))->toBeFalse()
        ->and(is_file($this->root.'/pushed-1/in/deb/icon.png'))->toBeFalse()
        ->and(is_file($this->root.'/pushed-1/in/deb/metainfo.xml'))->toBeFalse();
});

it('refuses an icon that is not square or not a hicolor size before pushing anything', function () {
    file_put_contents($this->root.'/app/art/icon.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAAAgCAYAAACinX6EAAAAC0lEQVR4nGNgGAUAAAEAAVYYRdAAAAAASUVORK5CYII='));

    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);
})->throws(RuntimeException::class, 'art/icon.png is a 64x32 PNG; the icon must be a square PNG of at least 16 px');

it('hands the image the extensions\' apt build packages and the .deb their run-time packages', function () {
    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest->with(['extensions' => ['gtk', 'qt']]), $this->root.'/build', $this->report);

    $in = $this->root.'/pushed-1/in';
    expect(file_get_contents($in.'/apt.build'))->toBe("libcurl4-openssl-dev\nlibgtk-4-dev\nqt6-base-dev\nqt6-multimedia-dev\n")
        ->and(file_get_contents($in.'/deb/depends'))->toBe('qt6-wayland')
        ->and(file_get_contents($in.'/deb/control'))->toContain("\nRecommends: libgtk-4-media-gstreamer\n");
});

it('writes no Recommends line and an empty depends file when no extension declares any', function () {
    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest->with(['extensions' => []]), $this->root.'/build', $this->report);

    expect(file_get_contents($this->root.'/pushed-1/in/deb/depends'))->toBe('')
        ->and(file_get_contents($this->root.'/pushed-1/in/deb/control'))->not->toContain('Recommends:');
});

it('recompiles when an extension\'s branch moves, and reuses while the commit stays', function () {
    $this->reference = 'one';
    $sources = new Sources($this->root.'/cache', fn (string $url): string => str_replace('ref-gtk', $this->reference, FakeSources::packagist($url)), fn (string $url, string $path) => file_put_contents($path, 'A'));
    $target = new DebTarget('x86_64', $sources, $this->docker, ['linux-x86_64' => 'gamingpc'], $this->root.'/env');

    $target->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);
    $target->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);
    $this->reference = 'two';
    $target->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);

    $hash = fn (int $n): string => file_get_contents($this->root."/pushed-{$n}/in/set.hash");
    expect($hash(1))->toBe($hash(2))->and($hash(3))->not->toBe($hash(1));
});

it('refuses an extension name it cannot compile', function () {
    $sources = new Sources($this->root.'/cache', fn (string $url): string => str_contains($url, '/nope') ? throw new NotFound('404') : FakeSources::packagist($url), fn (string $url, string $path) => file_put_contents($path, 'A'));
    $target = new DebTarget('x86_64', $sources, $this->docker, ['linux-x86_64' => 'gamingpc'], $this->root.'/env');

    $target->build($this->root.'/app.phar', $this->manifest->with(['extensions' => ['nope']]), $this->root.'/build', $this->report);
})->throws(RuntimeException::class, 'php-io-extensions/nope is not on Packagist');

it('leaves out extensions whose php-ext metadata excludes Linux, and says so', function () {
    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest->with(['extensions' => ['appkit', 'gtk', 'kqueue']]), $this->root.'/build', $this->report);

    expect(file_get_contents($this->root.'/pushed-1/in/extensions.list'))->toBe("epoll\t.\npcurl\text\nrasterize\t.\ngtk\t.\n")
        ->and(file_get_contents($this->root.'/pushed-1/in/configure.args'))->not->toContain('appkit')
        ->and(is_file($this->root.'/pushed-1/in/ext/appkit.zip'))->toBeFalse()
        ->and($this->lines)->toContain('Leaving out appkit: php-io-extensions/appkit v0.10.0 builds on darwin only')
        ->and($this->lines)->toContain('Leaving out kqueue: php-io-extensions/kqueue v0.10.0 does not build on linux');
});

it('clears the previous inputs in the volume before pushing, so a dropped icon does not ride along', function () {
    $target = ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc']);
    $target->build($this->root.'/app.phar', $this->manifest->with(['windowed' => false, 'icon' => null]), $this->root.'/build', $this->report);

    $runs = array_values(array_filter($this->fake->commands, fn (array $c): bool => $c[3] === 'run'));
    expect(array_slice($runs[0], -5))->toBe(['rm', '-rf', 'in', 'out', 'pkg'])
        ->and($runs[1])->toContain('tar');
});

it('requires an author for the package maintainer line', function () {
    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest->with(['author' => '']), $this->root.'/build', $this->report);
})->throws(RuntimeException::class, 'A .deb needs a maintainer: set author in build.json as "Name <email>"');

it('says the local docker engine does not answer when no host is named and it is down', function () {
    // docker info exits 0 with an empty answer when the daemon behind the context is stopped.
    $docker = new Docker(fn (array $command): string => $command[1] === 'context' ? "desktop-linux\n" : "\n");
    $target = new DebTarget('arm64', $this->sources, $docker, [], $this->root.'/env');

    expect($target->available())->toBeFalse()
        ->and($target->unavailableReason())->toBe('docker context desktop-linux does not answer: is its docker daemon running?');
});

it('takes a square icon of any size from 16 px and leaves the hicolor sizes to package.sh', function () {
    // The 1024 px icon a Mac build wants: getimagesize reads the IHDR, so patching its size is enough.
    $png = file_get_contents($this->root.'/app/art/icon.png');
    file_put_contents($this->root.'/app/art/icon.png', substr_replace($png, pack('NN', 1024, 1024), 16, 8));

    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);

    expect(file_get_contents($this->root.'/pushed-1/in/deb/icon.size'))->toBe('1024');
});

it('refuses an icon that is not a PNG', function () {
    file_put_contents($this->root.'/app/art/icon.png', base64_decode('R0lGODlhQABAAIAAAP///wAAACwAAAAAQABAAAACAkQBADs='));

    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);
})->throws(RuntimeException::class, 'art/icon.png is a 64x64 GIF; the icon must be a square PNG of at least 16 px');

it('compiles PHP\'s own extensions in by flag, never through Packagist, with what they need', function () {
    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest->with(['extensions' => ['sodium', 'gd', 'mysqli', 'xsl']]), $this->root.'/build', $this->report);

    $args = file_get_contents($this->root.'/pushed-1/in/configure.args');
    expect($args)->toContain("--with-sodium\n")->toContain("--enable-gd\n")->toContain("--with-mysqli\n")->toContain("--enable-mysqlnd\n")
        ->toContain("--with-xsl\n")->toContain("--enable-dom\n")->toContain("--with-libxml\n")
        ->and(glob($this->root.'/pushed-1/in/ext/*.zip'))->toBe([$this->root.'/pushed-1/in/ext/epoll.zip', $this->root.'/pushed-1/in/ext/pcurl.zip', $this->root.'/pushed-1/in/ext/rasterize.zip']);
});

it('names a php-src extension it does not compile yet instead of looking for it on Packagist', function (string $name) {
    expect(fn () => ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest->with(['extensions' => [$name]]), $this->root.'/build', $this->report))
        ->toThrow(RuntimeException::class, "{$name} ships with php-src, but venusian build does not compile it in yet");
})->with(['snmp', 'opcache']);

it('tags the build image with the hash of its Dockerfile, and recompiles PHP in a new image', function () {
    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);
    file_put_contents($this->root.'/env/Dockerfile', "FROM ubuntu:24.04\nRUN true\n");
    $this->fake->commands = [];
    ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report);

    expect(file_get_contents($this->root.'/pushed-2/in/set.hash'))->not->toBe(file_get_contents($this->root.'/pushed-1/in/set.hash'));

    expect($this->fake->commands[0])->toBe(['docker', '--context', 'gamingpc', 'image', 'inspect', '--format', '{{.Id}}', DebTarget::IMAGE.'-'.substr(sha1("FROM ubuntu:24.04\nRUN true\n"), 0, 12)]);
});
it('stops before packaging when the app does not boot in the container', function () {
    $this->fake->fail = fn (array $command): ?string => in_array('-c', $command, true) ? 'PHP Fatal error:  could not find driver' : null;

    expect(fn () => ($this->target)('x86_64', ['linux-x86_64' => 'gamingpc'])->build($this->root.'/app.phar', $this->manifest, $this->root.'/build', $this->report))
        ->toThrow(RuntimeException::class, 'Star Gazer does not start; the built binary, booting the packaged app, said:');
    expect(array_filter($this->fake->commands, fn (array $c): bool => in_array('package.sh', $c, true)))->toBe([]);
});

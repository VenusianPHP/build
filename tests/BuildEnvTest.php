<?php

/* The build environment files parse: shell with sh -n, YAML with the parser, and the Dockerfile names the floor. */
it('has shell scripts that parse', function () {
    foreach (['recipe.sh', 'package.sh', 'apt-build.sh'] as $script) {
        $process = new Symfony\Component\Process\Process(['sh', '-n', __DIR__.'/../build-env/'.$script]);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($script.': '.$process->getErrorOutput());
    }
});

it('builds on the Ubuntu 24.04 floor', function () {
    expect(file_get_contents(__DIR__.'/../build-env/Dockerfile'))->toStartWith('# The Linux build environment')
        ->and(file_get_contents(__DIR__.'/../build-env/Dockerfile'))->toContain("\nFROM ubuntu:24.04\n");
});

it('has a workflow that parses and pushes both architectures', function () {
    $yaml = file_get_contents(__DIR__.'/../.github/workflows/build-env.yml');
    $parsed = function_exists('yaml_parse') ? yaml_parse($yaml) : null;
    if ($parsed === null) {
        $process = new Symfony\Component\Process\Process(['ruby', '-ryaml', '-e', 'YAML.load_file(ARGV[0]); puts "ok"', __DIR__.'/../.github/workflows/build-env.yml']);
        $process->run();
        expect(trim($process->getOutput()))->toBe('ok', $process->getErrorOutput());
    }
    expect($yaml)->toContain('platforms: linux/amd64,linux/arm64')
        ->and($yaml)->toContain('ghcr.io/venusianphp/build-env:ubuntu24.04');
});

it('chains no commands with && where set -e would let a failure through', function () {
    // Under POSIX set -e a failing command before the last of an && list does not stop the script.
    foreach (['recipe.sh', 'package.sh', 'apt-build.sh'] as $script) {
        foreach (file(__DIR__.'/../build-env/'.$script) as $n => $line) {
            if (str_contains($line, '&&') && ! str_starts_with(ltrim($line), 'if ') && ! str_contains($line, '||')) {
                throw new RuntimeException("{$script}:".($n + 1).' chains with &&: '.trim($line));
            }
        }
    }
    expect(true)->toBeTrue();
});

it('installs the declared apt build packages the image lacks before configure and again before dpkg-shlibdeps, each in its own container', function () {
    $recipe = file_get_contents(__DIR__.'/../build-env/recipe.sh');
    $package = file_get_contents(__DIR__.'/../build-env/package.sh');
    $apt = file_get_contents(__DIR__.'/../build-env/apt-build.sh');

    expect($apt)->toContain('in/apt.build')
        ->and($apt)->toContain('dpkg -s')
        ->and($apt)->toContain('apt-get install')
        ->and(strpos($recipe, 'sh /work/apt-build.sh'))->toBeLessThan(strpos($recipe, './configure'))
        ->and(strpos($package, 'sh /work/apt-build.sh'))->toBeLessThan(strpos($package, 'dpkg-shlibdeps -O'))
        ->and($package)->toContain('$D/depends');
});

it('builds GLFW 3.4 and SDL3 static from checksummed archives, and installs no distribution GLFW beside them', function () {
    $dockerfile = file_get_contents(__DIR__.'/../build-env/Dockerfile');

    expect($dockerfile)->toContain('b5ec004b2712fd08e8861dc271428f048775200a2df719ccf575143ba749a3e9  /tmp/glfw.zip')
        ->and($dockerfile)->toContain('9c75cf16330322c217dedd2e0609f1124f1b54b8633e763467b4684d0f4334a3  /tmp/sdl3.tar.gz')
        ->and($dockerfile)->toContain('-DBUILD_SHARED_LIBS=OFF')
        ->and($dockerfile)->toContain('-DSDL_SHARED=OFF -DSDL_STATIC=ON')
        ->and(substr_count($dockerfile, 'sha256sum -c'))->toBe(4)
        ->and($dockerfile)->not->toContain('libglfw3-dev');
});

it('installs Vulkan-Headers 1.4.309 from a checksummed archive over the distribution\'s 1.3 headers', function () {
    $dockerfile = file_get_contents(__DIR__.'/../build-env/Dockerfile');

    expect($dockerfile)->toContain('437925ada160d86ed763d29dcb9318c1bb0d024d7deaf77bc7c170b8eb6b6f10  /tmp/vulkan-headers.tar.gz')
        ->and($dockerfile)->toContain('/usr/local/include')
        ->and(substr_count($dockerfile, 'sha256sum -c'))->toBe(4);
});

it('stops with buildconf\'s errors when it wrote no configure, which it does with exit 0 when m4 fails', function () {
    $recipe = file_get_contents(__DIR__.'/../build-env/recipe.sh');

    expect($recipe)->toContain("if [ ! -x configure ]; then\n    tail -20 ../buildconf.log >&2\n    exit 1\nfi")
        ->and(strpos($recipe, '[ ! -x configure ]'))->toBeLessThan(strpos($recipe, './configure $('));
});

it('gives GLFW\'s one unprefixed Wayland protocol table its _glfw_ prefix, so GLFW and SDL3 link into one binary', function () {
    $dockerfile = file_get_contents(__DIR__.'/../build-env/Dockerfile');

    expect($dockerfile)->toContain('objcopy --redefine-sym wp_fractional_scale_manager_v1_interface=_glfw_wp_fractional_scale_manager_v1_interface /usr/local/lib/libglfw3.a');
});

it('adds a declared run-time package to Depends only when dpkg-shlibdeps has not named it', function () {
    $package = file_get_contents(__DIR__.'/../build-env/package.sh');
    $start = strpos($package, '# Declared run-time packages');
    $merge = substr($package, $start, strpos($package, 'printf \'Depends:', $start) - $start);
    $script = "D=\$1\nDEPENDS='libc6 (>= 2.38), libwayland-egl1 (>= 1.15.0), libx11-6'\n{$merge}printf '%s' \"\$DEPENDS\"";
    $dir = sys_get_temp_dir().'/venusian-depends-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/depends', 'libwayland-egl1, qt6-wayland, libx11-6');
    file_put_contents($dir.'/merge.sh', $script);

    $out = shell_exec('sh '.escapeshellarg($dir.'/merge.sh').' '.escapeshellarg($dir));
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    expect($out)->toBe('libc6 (>= 2.38), libwayland-egl1 (>= 1.15.0), libx11-6, qt6-wayland');
});

it('builds libjpeg-turbo static with hidden symbols, so a .deb names no distribution libjpeg', function () {
    $dockerfile = file_get_contents(__DIR__.'/../build-env/Dockerfile');

    expect($dockerfile)->toContain('6f30092cef9fb839779646608f4ee14ae3cbac989c47fa05e841b0841f09878e  /tmp/libjpeg-turbo.tar.gz')
        ->and($dockerfile)->toContain('-DENABLE_SHARED=OFF -DENABLE_STATIC=ON')
        ->and($dockerfile)->toContain('-DCMAKE_C_VISIBILITY_PRESET=hidden')
        ->and(substr_count($dockerfile, 'sha256sum -c'))->toBe(4);
});

it('gives each t64-renamed dependency its name without t64 as an alternative, which Debian 13 uses for some', function () {
    $package = file_get_contents(__DIR__.'/../build-env/package.sh');
    $start = strpos($package, '# Ubuntu 24.04 names');
    $alternatives = substr($package, $start, strpos($package, '# Declared run-time packages', $start) - $start);
    $dir = sys_get_temp_dir().'/venusian-t64-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/t64.sh', "DEPENDS='libc6 (>= 2.38), libqt6gui6t64 (>= 6.1.2), libssl3t64 (>= 3.0.0), libx11-6'\n{$alternatives}printf '%s' \"\$DEPENDS\"");

    $out = shell_exec('sh '.escapeshellarg($dir.'/t64.sh'));
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    expect($out)->toBe('libc6 (>= 2.38), libqt6gui6t64 (>= 6.1.2) | libqt6gui6 (>= 6.1.2), libssl3t64 (>= 3.0.0) | libssl3 (>= 3.0.0), libx11-6');
});

it('adds the plain-name alternative only to names that end in t64, wherever they sit in an entry', function () {
    $package = file_get_contents(__DIR__.'/../build-env/package.sh');
    $start = strpos($package, '# Ubuntu 24.04 names');
    $alternatives = substr($package, $start, strpos($package, '# Declared run-time packages', $start) - $start);
    $dir = sys_get_temp_dir().'/venusian-t64-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/t64.sh', "DEPENDS='libt64x1 (>= 1), liba1 | libglib2.0-0t64 (>= 2.44.0), libb2t64'\n{$alternatives}printf '%s' \"\$DEPENDS\"");

    $out = shell_exec('sh '.escapeshellarg($dir.'/t64.sh'));
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    expect($out)->toBe('libt64x1 (>= 1), liba1 | libglib2.0-0t64 (>= 2.44.0) | libglib2.0-0 (>= 2.44.0), libb2t64 | libb2');
});

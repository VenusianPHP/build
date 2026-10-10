<?php

use Symfony\Component\Process\Process;

/* The macOS build scripts: they parse, pin every archive, and never chain with && where set -e lets a failure through. */
$dir = __DIR__.'/../../build-env/macos';

it('parses as POSIX sh', function () use ($dir) {
    foreach (glob($dir.'/*.sh') as $script) {
        $process = new Process(['sh', '-n', $script]);
        $process->run();
        expect($process->isSuccessful())->toBeTrue(basename($script).': '.$process->getErrorOutput());
    }
});

it('pins every library archive by URL and sha256', function () use ($dir) {
    $libs = file_get_contents($dir.'/libs.sh');

    foreach ([
        'https://github.com/openssl/openssl/releases/download/openssl-3.5.4/openssl-3.5.4.tar.gz 967311f84955316969bdb1d8d4b983718ef42338639c621ec4c34fddef355e99',
        'https://github.com/kkos/oniguruma/releases/download/v6.9.10/onig-6.9.10.tar.gz 2a5cfc5ae259e4e97f86b68dfffc152cdaffe94e2060b770cb827238d769fc05',
        'https://github.com/libjpeg-turbo/libjpeg-turbo/releases/download/3.2.0/libjpeg-turbo-3.2.0.tar.gz 6f30092cef9fb839779646608f4ee14ae3cbac989c47fa05e841b0841f09878e',
        'https://download.sourceforge.net/libpng/libpng-1.6.59.tar.xz d80dd2a38a37f803cb9b6ac7b14bd6e74ddc3b654780a8380bdf93523fdb4389',
        'https://download.osgeo.org/libtiff/tiff-4.7.1.tar.xz b92017489bdc1db3a4c97191aa4b75366673cb746de0dce5d7a749d5954681ba',
        'https://www.openldap.org/software/download/OpenLDAP/openldap-release/openldap-2.6.15.tgz bc91225dbfc50354033b1303bc91d1a7f6ddd1dc32fac950d79c28fe66d6bca8',
        'https://github.com/glfw/glfw/releases/download/3.4/glfw-3.4.zip b5ec004b2712fd08e8861dc271428f048775200a2df719ccf575143ba749a3e9',
        'https://github.com/libsdl-org/SDL/releases/download/release-3.4.18/SDL3-3.4.18.tar.gz 9c75cf16330322c217dedd2e0609f1124f1b54b8633e763467b4684d0f4334a3',
        'https://github.com/libusb/libusb/releases/download/v1.0.30/libusb-1.0.30.tar.bz2 fea36f34f9156400209595e300840767ab1a385ede1dc7ee893015aea9c6dbaf',
        'https://www.intra2net.com/en/developer/libftdi/download/libftdi1-1.5.tar.bz2 7c7091e9c86196148bd41177b4590dccb1510bfe6cea5bf7407ff194482eb049',
        'https://github.com/KhronosGroup/Vulkan-Headers/archive/refs/tags/v1.4.309.tar.gz 437925ada160d86ed763d29dcb9318c1bb0d024d7deaf77bc7c170b8eb6b6f10',
        'https://github.com/KhronosGroup/Vulkan-Loader/archive/refs/tags/v1.4.309.tar.gz 3e4085a55f6e356fe9dbd47e6dc762be732790add3532943da824b3e8c062827',
        'https://github.com/KhronosGroup/MoltenVK/releases/download/v1.4.2/MoltenVK-macos.tar f95765a6229cb7b915990a2890ce12ebe36a730b021545d3d52ae69ce4c4024e',
    ] as $pin) {
        expect($libs)->toContain("fetch {$pin} ");
    }

    expect($libs)->toContain('-mmacosx-version-min=14.0 -arch arm64')
        ->and($libs)->toContain("! -name 'libvulkan*' ! -name 'libMoltenVK.dylib'")
        ->and($libs)->toContain('-DCMAKE_IGNORE_PREFIX_PATH=/opt/homebrew;/usr/local')
        ->and($libs)->not->toContain('libldap.tbd')
        ->and($libs)->toContain('--disable-slapd --without-cyrus-sasl --with-tls=openssl')
        ->and(strrpos($libs, '.complete'))->toBeGreaterThan(strrpos($libs, 'pkg-config --exists'));
});

it('chains no commands with && where set -e would let a failure through', function () use ($dir) {
    foreach (glob($dir.'/*.sh') as $script) {
        foreach (file($script) as $n => $line) {
            if (str_contains($line, '&&') && ! str_contains($line, '||')) {
                throw new RuntimeException(basename($script).':'.($n + 1).' chains with &&: '.trim($line));
            }
        }
    }
    expect(true)->toBeTrue();
});

it('compiles natively at the floor and refuses a binary another Mac could not run', function () use ($dir) {
    $recipe = file_get_contents($dir.'/recipe.sh');

    expect($recipe)->toContain('-mmacosx-version-min=14.0 -arch arm64')
        ->and($recipe)->toContain('-Wl,-rpath,@executable_path/../Frameworks')
        ->and($recipe)->toContain("grep -v -E '^(/System/Library/|/usr/lib/|@rpath/)'")
        ->and($recipe)->toContain('[ "$MINOS" != "14.0" ]')
        ->and($recipe)->toContain('DYLD_LIBRARY_PATH="$PREFIX/lib"')
        ->and(strpos($recipe, 'LEAKS='))->toBeLessThan(strpos($recipe, 'cp "$BIN"'));
});

it('keeps the shell\'s compiler and linker variables out of both scripts', function () use ($dir) {
    foreach (['libs.sh', 'recipe.sh'] as $script) {
        expect(file_get_contents($dir.'/'.$script))
            ->toContain('unset CPPFLAGS CPATH C_INCLUDE_PATH CPLUS_INCLUDE_PATH OBJC_INCLUDE_PATH LIBRARY_PATH LIBS CC CXX OBJC OBJCFLAGS PKG_CONFIG_PATH');
    }
});

it('refuses objects built for a newer macOS and any rpath but the bundle\'s Frameworks', function () use ($dir) {
    $recipe = file_get_contents($dir.'/recipe.sh');

    expect($recipe)->toContain("grep \"was built for newer 'macOS' version\" ../make.log")
        ->and($recipe)->toContain("otool -l \"\$BIN\" | awk '/LC_RPATH/")
        ->and($recipe)->toContain('@executable_path/../Frameworks');
});

it('says where a failure\'s full log is, and shows the log when nothing in it matches', function () use ($dir) {
    expect(file_get_contents($dir.'/libs.sh'))->toContain('full log: $WORK/$1.log')
        ->and(file_get_contents($dir.'/recipe.sh'))->toContain('tail -40 ../make.log');
});

it('compiles no build machine path into OpenSSL or OpenLDAP', function () use ($dir) {
    $libs = file_get_contents($dir.'/libs.sh');

    expect($libs)->toContain('no-module no-engine')
        ->and($libs)->toContain('ENGINESDIR=/var/empty MODULESDIR=/var/empty')
        ->and($libs)->toContain('--sysconfdir=/etc --localstatedir=/var')
        ->and($libs)->toContain('make -C libraries install sysconfdir="$PREFIX/etc" localstatedir="$PREFIX/var"');
});

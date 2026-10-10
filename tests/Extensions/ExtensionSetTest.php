<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\Extensions\ExtensionSet;
use Venusian\Build\Tests\Fakes\FakeSources;

/*
 * The set one build compiles: the OS's base, the app's names, what those
 * pull in; php-src's own as flags, the rest from (fake) Packagist, kept or
 * left out by the OS the binary runs on.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-set-'.bin2hex(random_bytes(4));
    $this->sources = FakeSources::make($this->root);
    $this->dirs = ['iconv' => '/SDK/usr', 'bz2' => '/SDK/usr', 'ldap' => '/P'];
    $this->lines = [];
    $this->report = function (string $line): void {
        $this->lines[] = $line;
    };
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('gives a macOS build kqueue, pcurl and curl over the framework base', function () {
    [$names, $args, $packages] = (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve([], false, $this->report);

    expect($names)->toBe(['ctype', 'filter', 'mbstring', 'openssl', 'pdo', 'kqueue', 'pcurl', 'curl'])
        ->and($args)->toContain('--enable-venusian', '--disable-rpath', '--with-curl', '--enable-kqueue', '--enable-pcurl')
        ->and(array_column($packages, 'name'))->toBe(['kqueue', 'pcurl']);
});

it('keeps the Linux set as the .deb has it', function () {
    [$names] = (new ExtensionSet($this->sources, 'linux'))->resolve(['appkit'], false, $this->report);

    expect($names)->toBe(['ctype', 'filter', 'mbstring', 'openssl', 'pdo', 'epoll', 'pcurl', 'sockets', 'curl'])
        ->and($this->lines)->toContain('Leaving out appkit: php-io-extensions/appkit v0.10.0 builds on darwin only');
});

it('points iconv, bz2 and ldap at the directories it is given, and uses plain flags without them', function () {
    [, $mac] = (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve(['iconv', 'bz2', 'ldap'], false, $this->report);
    [, $linux] = (new ExtensionSet($this->sources, 'linux'))->resolve(['iconv', 'bz2', 'ldap'], false, $this->report);

    expect($mac)->toContain('--with-iconv=/SDK/usr', '--with-bz2=/SDK/usr', '--with-ldap=/P')
        ->and($linux)->toContain('--with-iconv', '--with-bz2', '--with-ldap');
});

it('leaves GTK and Qt out of a macOS build and says why', function () {
    [$names, , $packages] = (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve(['appkit', 'gtk', 'qt'], false, $this->report);

    expect($names)->toContain('appkit')->not->toContain('gtk')->not->toContain('qt')
        ->and(array_column($packages, 'name'))->toBe(['kqueue', 'pcurl', 'appkit'])
        ->and($this->lines)->toContain('Leaving out gtk: a macOS build ships AppKit; GTK and Qt run on a Mac from source for testing')
        ->and($this->lines)->toContain('Leaving out qt: a macOS build ships AppKit; GTK and Qt run on a Mac from source for testing');
});

it('refuses a php-src extension a macOS build has no library for', function () {
    (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve(['intl'], false, $this->report);
})->throws(RuntimeException::class, 'intl ships with php-src, but a macOS build has no library for it');

it('compiles thread safe when asked', function () {
    [, $args] = (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve([], true, $this->report);

    expect($args)->toContain('--enable-zts');
});

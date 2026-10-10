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

it('gives a macOS build kqueue, pcurl, rasterize and curl over the framework base', function () {
    [$names, $args, $packages] = (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve([], false, $this->report);

    expect($names)->toBe(['ctype', 'filter', 'mbstring', 'openssl', 'pdo', 'kqueue', 'pcurl', 'rasterize', 'curl'])
        ->and($args)->toContain('--enable-venusian', '--disable-rpath', '--with-curl', '--enable-kqueue', '--enable-pcurl', '--enable-rasterize')
        ->and(array_column($packages, 'name'))->toBe(['kqueue', 'pcurl', 'rasterize']);
});

it('keeps the Linux set as the .deb has it', function () {
    [$names] = (new ExtensionSet($this->sources, 'linux'))->resolve(['appkit'], false, $this->report);

    expect($names)->toBe(['ctype', 'filter', 'mbstring', 'openssl', 'pdo', 'epoll', 'pcurl', 'rasterize', 'sockets', 'curl'])
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
        ->and(array_column($packages, 'name'))->toBe(['kqueue', 'pcurl', 'rasterize', 'appkit'])
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

it('reports the extensions vendor code calls that the build does not compile in', function () {
    (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve([], false, $this->report, [
        'intl' => ['laravel/framework', 'nesbot/carbon', 'symfony/string'], 'pcurl' => ['venusian/framework'], 'dom' => ['league/commonmark'],
    ]);

    expect($this->lines)->toContain('Vendor code also calls, not compiled in: dom (league/commonmark), intl (laravel/framework, nesbot/carbon +1); add one to build.json extensions if the app needs it');
});

it('compiles a php-ext package named in full under its extension name, once, with what it needs', function () {
    [$names, $args, $packages] = (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve(['phpredis/phpredis', 'redis'], false, $this->report);

    $redis = array_values(array_filter($packages, fn (array $p): bool => $p['name'] === 'redis'));
    expect(array_count_values($names)['redis'])->toBe(1)
        ->and($names)->toContain('session')
        ->and($args)->toContain('--enable-redis')
        ->and($args)->toContain('--enable-session')
        ->and($redis[0]['package'])->toBe('phpredis/phpredis');
});

it('refuses an extension that cannot build at the thread safety of this build, naming it', function () {
    (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve(['pecl/parallel'], false, $this->report);
})->throws(RuntimeException::class, 'parallel (pecl/parallel v1.2.15) needs thread-safe PHP, and this build compiles NTS (build.json "zts", else the PHP running the build): run the build with a ZTS PHP (zenusian), or set "zts": true in build.json.');

it('compiles a thread-safe-only extension into a ZTS build', function () {
    [$names, $args] = (new ExtensionSet($this->sources, 'darwin', $this->dirs))->resolve(['pecl/parallel'], true, $this->report);

    expect($names)->toContain('parallel')
        ->and($args)->toContain('--enable-parallel');
});

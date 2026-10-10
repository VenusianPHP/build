<?php

namespace Venusian\Build\Extensions;

use Closure;
use RuntimeException;
use Venusian\Build\Sources\Sources;

/**
 * The extensions one build compiles in: the base the framework and the OS's
 * loop need, the app's, and what those pull in. php-src's own become
 * configure flags; the rest come from php-io-extensions on the build's line.
 * An extension whose php-ext metadata leaves the OS out is left out and
 * reported, as are GTK and Qt on macOS, where apps ship AppKit.
 */
final class ExtensionSet
{
    /** php-src's own extensions and the flag that compiles each in; a name outside this list and the uncompiled ones resolves through Packagist. */
    public const CORE = [
        'bcmath' => '--enable-bcmath', 'ctype' => '--enable-ctype', 'curl' => '--with-curl', 'dom' => '--enable-dom', 'fileinfo' => '--enable-fileinfo',
        'filter' => '--enable-filter', 'gmp' => '--with-gmp', 'iconv' => '--with-iconv', 'intl' => '--enable-intl', 'libxml' => '--with-libxml',
        'mbstring' => '--enable-mbstring', 'openssl' => '--with-openssl', 'pcntl' => '--enable-pcntl', 'pdo' => '--enable-pdo',
        'pdo_mysql' => '--with-pdo-mysql', 'pdo_pgsql' => '--with-pdo-pgsql', 'pdo_sqlite' => '--with-pdo-sqlite', 'phar' => '--enable-phar',
        'posix' => '--enable-posix', 'session' => '--enable-session', 'simplexml' => '--enable-simplexml', 'sockets' => '--enable-sockets',
        'sqlite3' => '--with-sqlite3', 'tokenizer' => '--enable-tokenizer', 'xml' => '--enable-xml', 'xmlreader' => '--enable-xmlreader',
        'xmlwriter' => '--enable-xmlwriter', 'zip' => '--with-zip', 'zlib' => '--with-zlib',
        'bz2' => '--with-bz2', 'calendar' => '--enable-calendar', 'exif' => '--enable-exif', 'ffi' => '--with-ffi', 'ftp' => '--enable-ftp',
        'gd' => '--enable-gd', 'gettext' => '--with-gettext', 'ldap' => '--with-ldap', 'mysqli' => '--with-mysqli', 'mysqlnd' => '--enable-mysqlnd',
        'pgsql' => '--with-pgsql', 'readline' => '--with-libedit', 'shmop' => '--enable-shmop', 'soap' => '--enable-soap',
        'sodium' => '--with-sodium', 'sysvmsg' => '--enable-sysvmsg', 'sysvsem' => '--enable-sysvsem', 'sysvshm' => '--enable-sysvshm',
        'tidy' => '--with-tidy', 'xsl' => '--with-xsl',
    ];

    /** php-src's own extensions no build compiles in: their libraries are in neither the image nor the SDK, or (opcache) PHP 8.4 builds it only as a zend_extension the SAPI never loads. */
    private const UNCOMPILED = ['dba', 'dl_test', 'enchant', 'odbc', 'opcache', 'pdo_dblib', 'pdo_firebird', 'pdo_odbc', 'snmp', 'zend_test'];

    /** php-src's own extensions a macOS build leaves out: neither the macOS SDK nor the static library set carries their library (fact 24). */
    private const UNCOMPILED_DARWIN = ['gettext', 'gmp', 'intl', 'pdo_pgsql', 'pgsql', 'sodium', 'tidy', 'zip'];

    /** Always in PHP; never named to configure. */
    public const ALWAYS = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard'];

    /** What the framework requires of every app, the OS's loop backend, HTTP on the loop, and rasterize, Surface's 'extended' drawing driver in C (Angel, 2026-10-09). */
    public const BASE = [
        'linux' => ['ctype', 'filter', 'mbstring', 'openssl', 'pdo', 'epoll', 'pcurl', 'rasterize'],
        'darwin' => ['ctype', 'filter', 'mbstring', 'openssl', 'pdo', 'kqueue', 'pcurl', 'rasterize'],
    ];

    /** An extension that pulls another in: declared by PHP_ADD_EXTENSION_DEP in its config.m4. */
    private const NEEDS = [
        'dom' => ['libxml'], 'simplexml' => ['libxml'], 'xml' => ['libxml'], 'xmlreader' => ['libxml'], 'xmlwriter' => ['libxml'],
        'pdo_mysql' => ['pdo', 'mysqlnd'], 'pdo_pgsql' => ['pdo'], 'pdo_sqlite' => ['pdo'], 'pcurl' => ['curl'], 'epoll' => ['sockets'],
        'mysqli' => ['mysqlnd'], 'soap' => ['libxml'], 'xsl' => ['libxml', 'dom'],
    ];

    /** Toolkits a macOS build never ships (Angel, 2026-10-09). */
    private const NOT_ON_DARWIN = ['gtk', 'qt'];

    private const VENDOR = 'php-io-extensions';

    /**
     * @param  string  $os  linux or darwin: the OS the binary runs on, as php-ext os-families names it
     * @param  array<string, string>  $directories  name => directory, for the core extensions whose configure check takes a path
     *                                              rather than pkg-config (macOS: iconv and bz2 in the SDK, ldap in the static set)
     */
    public function __construct(
        private readonly Sources $sources,
        private readonly string $os,
        private readonly array $directories = [],
    ) {}

    /**
     * @param  list<string>  $wanted  the app's extension names
     * @param  Closure(string): void  $report
     * @param  array<string, list<string>>  $uses  extension => packages whose code calls it
     * @return array{0: list<string>, 1: list<string>, 2: list<array{name: string, version: string, reference: string, path: string, build_path: string, configure: string, os_families: list<string>, os_families_exclude: list<string>, apt_build: list<string>, apt_depends: list<string>, apt_recommends: list<string>}>}
     */
    public function resolve(array $wanted, bool $zts, Closure $report, array $uses = []): array
    {
        $names = [];
        $queue = [...self::BASE[$this->os], ...array_map('strtolower', $wanted)];

        while ($queue !== []) {
            $name = array_shift($queue);
            if (in_array($name, self::ALWAYS, true) || in_array($name, $names, true)) {
                continue;
            }
            $names[] = $name;
            array_push($queue, ...(self::NEEDS[$name] ?? []));
        }

        // No RPATH: a library directory of the build machine must never be searched before the system's.
        $args = ['--disable-all', '--disable-cli', '--disable-cgi', '--disable-phpdbg', '--disable-rpath', '--enable-venusian', '--enable-phar'];
        if ($zts) {
            $args[] = '--enable-zts';
        }
        $built = [];
        $packages = [];

        foreach ($names as $name) {
            if (in_array($name, self::UNCOMPILED, true)) {
                throw new RuntimeException("{$name} ships with php-src, but venusian build does not compile it in yet; ask for it on VenusianPHP/build.");
            }
            if ($this->os === 'darwin' && in_array($name, self::UNCOMPILED_DARWIN, true)) {
                throw new RuntimeException("{$name} ships with php-src, but a macOS build has no library for it: neither the macOS SDK nor venusian build's static set carries one; ask for it on VenusianPHP/build.");
            }
            if ($this->os === 'darwin' && in_array($name, self::NOT_ON_DARWIN, true)) {
                $report("Leaving out {$name}: a macOS build ships AppKit; GTK and Qt run on a Mac from source for testing");

                continue;
            }
            if (isset(self::CORE[$name])) {
                $built[] = $name;
                $args[] = isset($this->directories[$name]) ? self::CORE[$name].'='.$this->directories[$name] : self::CORE[$name];

                continue;
            }
            $package = $this->sources->extension($name);
            $families = $package['os_families'];
            if (in_array($this->os, $package['os_families_exclude'], true)) {
                $report("Leaving out {$name}: ".self::VENDOR."/{$name} {$package['version']} does not build on {$this->os}");

                continue;
            }
            if ($families !== [] && ! in_array($this->os, $families, true)) {
                $report("Leaving out {$name}: ".self::VENDOR."/{$name} {$package['version']} builds on ".implode(', ', $families).' only');

                continue;
            }
            $built[] = $name;
            $packages[] = ['name' => $name, ...$package];
            $args[] = $package['configure'];
        }

        $missing = array_diff_key($uses, array_flip([...$built, ...self::ALWAYS]));
        if ($missing !== []) {
            ksort($missing);
            $report('Vendor code also calls, not compiled in: '.implode(', ', array_map(
                fn (string $extension, array $packages): string => $extension.' ('.implode(', ', array_slice($packages, 0, 2)).(count($packages) > 2 ? ' +'.(count($packages) - 2) : '').')',
                array_keys($missing), $missing,
            )).'; add one to build.json extensions if the app needs it');
        }

        return [$built, $args, $packages];
    }
}

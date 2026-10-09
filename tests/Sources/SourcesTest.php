<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\Sources\Sources;

/*
 * Sources fetches php-src from php.net, the SAPI tag from GitHub and each
 * extension's latest tagged archive from Packagist into a cache, once.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-sources-'.bin2hex(random_bytes(4));
    $this->gets = [];
    $this->downloads = [];
    $get = function (string $url): string {
        $this->gets[] = $url;

        return json_encode(['packages' => ['php-io-extensions/gtk' => [
            ['version' => 'v0.10.1', 'dist' => ['url' => 'https://api.github.com/repos/php-io-extensions/gtk/zipball/abc', 'type' => 'zip'], 'php-ext' => ['extension-name' => 'gtk', 'build-path' => null, 'os-families' => ['linux', 'darwin'], 'configure-options' => [['name' => 'enable-gtk']]]],
            ['version' => 'v0.10.1', 'dist' => ['url' => 'https://api.github.com/repos/php-io-extensions/gtk/zipball/old', 'type' => 'zip']],
        ]]]);
    };
    $download = function (string $url, string $path): void {
        $this->downloads[] = $url;
        file_put_contents($path, "ARCHIVE:{$url}");
    };
    $this->sources = new Sources($this->root, $get, $download);
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('downloads php-src once into the cache', function () {
    $first = $this->sources->phpSrc();
    $second = $this->sources->phpSrc();

    expect($first)->toBe(['path' => $this->root.'/php-src/php-8.4.26.tar.xz', 'version' => '8.4.26'])
        ->and($second)->toBe($first)
        ->and($this->downloads)->toBe(['https://www.php.net/distributions/php-8.4.26.tar.xz']);
});

it('downloads the SAPI tag archive from GitHub', function () {
    expect($this->sources->sapi())->toBe(['path' => $this->root.'/sapi/sapi-v0.10.1.tar.gz', 'version' => 'v0.10.1'])
        ->and($this->downloads)->toBe(['https://github.com/VenusianPHP/sapi/archive/refs/tags/v0.10.1.tar.gz']);
});

it('takes the latest tagged release of an extension from Packagist with its build path and configure flag', function () {
    $gtk = $this->sources->extension('gtk');

    expect($gtk)->toBe(['path' => $this->root.'/ext/gtk-v0.10.1.zip', 'version' => 'v0.10.1', 'build_path' => '.', 'configure' => '--enable-gtk', 'os_families' => ['linux', 'darwin'], 'os_families_exclude' => []])
        ->and($this->gets)->toBe(['https://repo.packagist.org/p2/php-io-extensions/gtk.json'])
        ->and($this->downloads)->toBe(['https://api.github.com/repos/php-io-extensions/gtk/zipball/abc']);

    $this->sources->extension('gtk');
    expect($this->downloads)->toHaveCount(1)->and($this->gets)->toHaveCount(2);
});

it('names an extension Packagist does not have', function () {
    $sources = new Sources($this->root, fn (string $url): string => throw new RuntimeException('404'), fn () => null);

    $sources->extension('nope');
})->throws(RuntimeException::class, 'php-io-extensions/nope is not on Packagist');

it('refuses an extension release without php-ext metadata', function () {
    $sources = new Sources($this->root, fn (string $url): string => json_encode(['packages' => ['php-io-extensions/x' => [['version' => 'v1.0.0', 'dist' => ['url' => 'u']]]]]), fn () => null);

    $sources->extension('x');
})->throws(RuntimeException::class, 'php-io-extensions/x v1.0.0 declares no php-ext build metadata');

it('saves a download only when every byte the server announced arrived', function () {
    mkdir($this->root, 0777, true);
    $in = fopen('php://memory', 'w+b');
    fwrite($in, 'ARCHIVE');
    rewind($in);

    Sources::save($in, $this->root.'/whole.tar.xz', 7);

    expect(file_get_contents($this->root.'/whole.tar.xz'))->toBe('ARCHIVE');
});

it('refuses a download cut short and leaves no file behind', function () {
    mkdir($this->root, 0777, true);
    $in = fopen('php://memory', 'w+b');
    fwrite($in, 'ARCH');
    rewind($in);

    try {
        Sources::save($in, $this->root.'/cut.tar.xz', 7);
    } finally {
        expect(is_file($this->root.'/cut.tar.xz'))->toBeFalse();
    }
})->throws(RuntimeException::class, 'Download cut short: 4 of 7 bytes');

it('reads the Content-Length of the final response, not of a redirect before it', function () {
    expect(Sources::contentLength(['HTTP/1.1 302 Found', 'Content-Length: 0', 'Location: https://codeload/x', 'HTTP/1.1 200 OK', 'Transfer-Encoding: chunked']))->toBeNull()
        ->and(Sources::contentLength(['HTTP/1.1 302 Found', 'Content-Length: 0', 'HTTP/1.1 200 OK', 'content-length: 13087']))->toBe(13087);
});

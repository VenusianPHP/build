<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\Sources\NotFound;
use Venusian\Build\Sources\Sources;

/*
 * Sources fetches php-src from php.net, the SAPI tag from GitHub and each
 * extension's 0.10 line archive from Packagist into a cache, once.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-sources-'.bin2hex(random_bytes(4));
    $this->gets = [];
    $this->downloads = [];
    $gtk = ['extension-name' => 'gtk', 'build-path' => null, 'os-families' => ['linux', 'darwin'], 'configure-options' => [['name' => 'enable-gtk']]];
    $this->indexes = [
        // Packagist minifies: each later entry carries only what changed from the one before it.
        'https://repo.packagist.org/p2/php-io-extensions/gtk~dev.json' => ['minified' => 'composer/2.0', 'packages' => ['php-io-extensions/gtk' => [
            ['version' => 'dev-main', 'dist' => ['url' => 'https://x/gtk-main', 'type' => 'zip', 'reference' => 'main1'], 'php-ext' => $gtk,
                'extra' => ['venusian' => ['system' => ['apt' => ['build' => ['libgtk-4-dev'], 'recommends' => ['libgtk-4-media-gstreamer']], 'brew' => ['build' => ['gtk4']]]]]],
            ['version' => '0.10.x-dev', 'dist' => ['url' => 'https://api.github.com/repos/php-io-extensions/gtk/zipball/abc123', 'type' => 'zip', 'reference' => 'abc123']],
            ['version' => '0.8.x-dev', 'dist' => ['url' => 'https://x/gtk-eight', 'type' => 'zip', 'reference' => 'eight'], 'extra' => '__unset'],
        ]]],
        'https://repo.packagist.org/p2/php-io-extensions/gtk.json' => ['packages' => ['php-io-extensions/gtk' => [
            ['version' => 'v0.10.0', 'dist' => ['url' => 'https://x/gtk-tag', 'type' => 'zip', 'reference' => 'tag'], 'php-ext' => $gtk],
        ]]],
        // No branch on the line: the newest 0.10 tag, never a newer line's.
        'https://repo.packagist.org/p2/php-io-extensions/pcurl.json' => ['packages' => ['php-io-extensions/pcurl' => [
            ['version' => 'v0.11.0', 'dist' => ['url' => 'https://x/pcurl-eleven', 'type' => 'zip', 'reference' => 'eleven'], 'php-ext' => ['build-path' => 'ext']],
            ['version' => '0.10.1', 'dist' => ['url' => 'https://x/pcurl-101', 'type' => 'zip', 'reference' => 'old'], 'php-ext' => ['build-path' => 'ext']],
            ['version' => '0.10.2', 'dist' => ['url' => 'https://x/pcurl-102', 'type' => 'zip', 'reference' => 'b4fca50'], 'php-ext' => ['build-path' => 'ext']],
            ['version' => '0.10.10-alpha', 'dist' => ['url' => 'https://x/pcurl-alpha', 'type' => 'zip', 'reference' => 'alpha'], 'php-ext' => ['build-path' => 'ext']],
        ]]],
        'https://repo.packagist.org/p2/php-io-extensions/old.json' => ['packages' => ['php-io-extensions/old' => [
            ['version' => '0.7.0', 'dist' => ['url' => 'https://x/old', 'type' => 'zip', 'reference' => 'o'], 'php-ext' => ['build-path' => 'ext']],
        ]]],
        'https://repo.packagist.org/p2/php-io-extensions/old~dev.json' => ['packages' => ['php-io-extensions/old' => [
            ['version' => '0.7.x-dev', 'dist' => ['url' => 'https://x/old-dev', 'type' => 'zip', 'reference' => 'od'], 'php-ext' => ['build-path' => 'ext']],
        ]]],
        'https://repo.packagist.org/p2/php-io-extensions/bare~dev.json' => ['packages' => ['php-io-extensions/bare' => [
            ['version' => '0.10.x-dev', 'dist' => ['url' => 'https://x/bare', 'type' => 'zip', 'reference' => 'b']],
        ]]],
    ];
    $get = function (string $url): string {
        $this->gets[] = $url;

        return isset($this->indexes[$url]) ? json_encode($this->indexes[$url]) : throw new NotFound('404');
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
    expect($this->sources->sapi())->toBe(['path' => $this->root.'/sapi/sapi-v0.10.2.tar.gz', 'version' => 'v0.10.2'])
        ->and($this->downloads)->toBe(['https://github.com/VenusianPHP/sapi/archive/refs/tags/v0.10.2.tar.gz']);
});

it('takes the 0.10.x-dev branch ahead of any tag, with its commit, build path, flag and system packages', function () {
    $gtk = $this->sources->extension('gtk');

    expect($gtk)->toBe([
        'path' => $this->root.'/ext/gtk-0.10.x-dev-abc123.zip', 'version' => '0.10.x-dev', 'reference' => 'abc123', 'build_path' => '.', 'configure' => '--enable-gtk',
        'os_families' => ['linux', 'darwin'], 'os_families_exclude' => [],
        'apt_build' => ['libgtk-4-dev'], 'apt_depends' => [], 'apt_recommends' => ['libgtk-4-media-gstreamer'],
    ])
        ->and($this->gets)->toBe(['https://repo.packagist.org/p2/php-io-extensions/gtk~dev.json'])
        ->and($this->downloads)->toBe(['https://api.github.com/repos/php-io-extensions/gtk/zipball/abc123']);

    $this->sources->extension('gtk');
    expect($this->downloads)->toHaveCount(1)->and($this->gets)->toHaveCount(2);
});

it('takes the highest 0.10 tag when no 0.10.x-dev branch exists, whatever order Packagist lists them in, never a newer line\'s', function () {
    $pcurl = $this->sources->extension('pcurl');

    expect($pcurl['version'])->toBe('0.10.2')
        ->and($pcurl['reference'])->toBe('b4fca50')
        ->and($pcurl['build_path'])->toBe('ext')
        ->and($pcurl['configure'])->toBe('--enable-pcurl')
        ->and($pcurl['apt_build'])->toBe([])
        ->and($pcurl['path'])->toBe($this->root.'/ext/pcurl-0.10.2-b4fca50.zip')
        ->and($this->gets)->toBe(['https://repo.packagist.org/p2/php-io-extensions/pcurl~dev.json', 'https://repo.packagist.org/p2/php-io-extensions/pcurl.json']);
});

it('refuses an extension with neither a 0.10.x-dev branch nor a 0.10 tag', function () {
    $this->sources->extension('old');
})->throws(RuntimeException::class, 'php-io-extensions/old has no 0.10.x-dev branch and no 0.10 tag on Packagist');

it('names an extension Packagist does not have', function () {
    $sources = new Sources($this->root, fn (string $url): string => throw new NotFound('HTTP/1.1 404 Not Found'), fn () => null);

    $sources->extension('nope');
})->throws(RuntimeException::class, 'php-io-extensions/nope is not on Packagist; tag and publish it, or drop nope from the app.');

it('stops with the reason when Packagist cannot be reached for the branch index, instead of falling back to a tag', function () {
    $sources = new Sources($this->root, fn (string $url): string => str_contains($url, '~dev') ? throw new RuntimeException('Cannot reach '.$url.': php_network_getaddresses: getaddrinfo failed') : json_encode($this->indexes['https://repo.packagist.org/p2/php-io-extensions/gtk.json']), fn () => null);

    $sources->extension('gtk');
})->throws(RuntimeException::class, 'Cannot reach Packagist for php-io-extensions/gtk: Cannot reach https://repo.packagist.org/p2/php-io-extensions/gtk~dev.json: php_network_getaddresses: getaddrinfo failed');

it('stops with the reason when the tag index cannot be read after the branch index had no 0.10.x-dev', function () {
    $sources = new Sources($this->root, fn (string $url): string => str_contains($url, '~dev') ? json_encode($this->indexes['https://repo.packagist.org/p2/php-io-extensions/old~dev.json']) : throw new RuntimeException('HTTP/1.1 503 Service Unavailable'), fn () => null);

    $sources->extension('old');
})->throws(RuntimeException::class, 'Cannot reach Packagist for php-io-extensions/old: HTTP/1.1 503 Service Unavailable');

it('refuses an extension release without php-ext metadata', function () {
    $this->sources->extension('bare');
})->throws(RuntimeException::class, 'php-io-extensions/bare 0.10.x-dev declares no php-ext build metadata');

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

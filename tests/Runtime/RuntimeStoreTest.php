<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\Runtime\MicroCombiner;
use Venusian\Build\Runtime\RuntimeStore;
use Venusian\Build\Tests\Fakes\FakeReleases;

/*
 * The runtime store downloads a php-bin release once, extracts the nested
 * zip for the target, and learns the runtime's built-in extensions by
 * running it with a probe payload.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-runtimes-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('downloads once, extracts the nested zip and probes the built-in extensions', function () {
    $releases = new FakeReleases;
    $store = new RuntimeStore($this->root, $releases, new MicroCombiner);

    $runtime = $store->resolve('phpacker/php-bin', 'mac', 'arm64', '8.4');

    expect($runtime->sfx)->toBe($this->root.'/phpacker/php-bin/0.4.0/mac/arm64/8.4/micro.sfx')
        ->and(is_executable($runtime->sfx))->toBeTrue()
        ->and($runtime->extensions)->toBe(['Core', 'json', 'phar'])
        ->and($runtime->tag)->toBe('0.4.0')
        ->and(glob($this->root.'/phpacker/php-bin/0.4.0/mac/arm64/8.4/*'))->toBe([
            $this->root.'/phpacker/php-bin/0.4.0/mac/arm64/8.4/extensions.json',
            $this->root.'/phpacker/php-bin/0.4.0/mac/arm64/8.4/micro.sfx',
        ]);

    $store->resolve('phpacker/php-bin', 'mac', 'arm64', '8.4');
    expect($releases->downloads)->toBe(1);
});

it('names the missing runtime when the release has no build for the target', function () {
    $store = new RuntimeStore($this->root, new FakeReleases, new MicroCombiner);

    $store->resolve('phpacker/php-bin', 'linux', 'x64', '8.4');
})->throws(RuntimeException::class, 'bin/linux/x64/php-8.4.zip');

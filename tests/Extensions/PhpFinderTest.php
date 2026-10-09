<?php

use Venusian\Build\Extensions\PhpFinder;

/*
 * The .so files come from the PHP that runs venusian. A runtime built to
 * match takes it as it is; an NTS-only runtime takes an NTS PHP's own
 * extension_dir, or the NTS directory beside a ZTS one. build.json php names
 * another PHP outright.
 */
$describe = fn (array $machines) => fn (string $binary): ?array => $machines[$binary] ?? null;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-finder-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/pecl/20240924-zts', 0777, true);
    mkdir($this->root.'/pecl/20240924', 0777, true);
});

afterEach(function () {
    (new Symfony\Component\Filesystem\Filesystem)->remove($this->root);
});

it('takes the extension_dir of an NTS PHP running venusian', function () use ($describe) {
    $finder = new PhpFinder(null, $describe(['/opt/php84/bin/php' => ['zts' => false, 'version' => '8.4.25', 'extension_dir' => '/opt/ext/']]), '/opt/php84/bin/php');

    expect($finder->nts())->toBe(['binary' => '/opt/php84/bin/php', 'version' => '8.4', 'zts' => false, 'extension_dir' => '/opt/ext']);
});

it('takes the NTS directory beside the extension_dir of a ZTS PHP running venusian', function () use ($describe) {
    $finder = new PhpFinder(null, $describe(['/opt/zts/php' => ['zts' => true, 'version' => '8.4.25', 'extension_dir' => $this->root.'/pecl/20240924-zts']]), '/opt/zts/php');

    expect($finder->nts())->toBe(['binary' => '/opt/zts/php', 'version' => '8.4', 'zts' => false, 'extension_dir' => $this->root.'/pecl/20240924']);
});

it('names the missing NTS directory beside a ZTS PHP', function () use ($describe) {
    rmdir($this->root.'/pecl/20240924');

    (new PhpFinder(null, $describe(['/opt/zts/php' => ['zts' => true, 'version' => '8.4.25', 'extension_dir' => $this->root.'/pecl/20240924-zts']]), '/opt/zts/php'))->nts();
})->throws(RuntimeException::class, 'no NTS extension directory beside');

it('takes the configured binary when it is NTS, over the running PHP', function () use ($describe) {
    $finder = new PhpFinder('/opt/php84/bin/php', $describe([
        '/opt/php84/bin/php' => ['zts' => false, 'version' => '8.4.25', 'extension_dir' => '/opt/ext'],
        '/opt/zts/php' => ['zts' => true, 'version' => '8.4.25', 'extension_dir' => '/z'],
    ]), '/opt/zts/php');

    expect($finder->nts()['binary'])->toBe('/opt/php84/bin/php');
});

it('refuses a configured ZTS binary by name', function () use ($describe) {
    (new PhpFinder('/opt/zts/php', $describe(['/opt/zts/php' => ['zts' => true, 'version' => '8.4.25', 'extension_dir' => '/z']]), '/opt/zts/php'))->nts();
})->throws(RuntimeException::class, '/opt/zts/php is ZTS');

it('says when the binary is not a PHP', function () use ($describe) {
    (new PhpFinder(null, $describe([]), '/not/php'))->nts();
})->throws(RuntimeException::class, '/not/php is not a PHP binary');

it('takes a ZTS PHP as it is for a runtime built to match it', function () use ($describe) {
    $finder = new PhpFinder(null, $describe(['/usr/local/bin/php' => ['zts' => true, 'version' => '8.4.20', 'extension_dir' => '/usr/local/php84-zts/ext/']]), '/usr/local/bin/php');

    expect($finder->running())->toBe(['binary' => '/usr/local/bin/php', 'version' => '8.4', 'zts' => true, 'extension_dir' => '/usr/local/php84-zts/ext']);
});

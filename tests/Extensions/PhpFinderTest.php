<?php

use Venusian\Build\Extensions\PhpFinder;

/*
 * The runtime is NTS, so the .so files must come from an NTS PHP of the
 * same minor. The finder takes the configured binary or searches PATH.
 */
$describe = fn (array $machines) => fn (string $binary): ?array => $machines[$binary] ?? null;
$find = fn (array $path) => fn (string $name): ?string => $path[$name] ?? null;

it('takes the configured binary when it is NTS', function () use ($describe, $find) {
    $finder = new PhpFinder('/opt/php84/bin/php', $describe(['/opt/php84/bin/php' => ['zts' => false, 'version' => '8.4.25', 'extension_dir' => '/opt/ext']]), $find([]));

    expect($finder->nts())->toBe(['binary' => '/opt/php84/bin/php', 'version' => '8.4', 'extension_dir' => '/opt/ext']);
});

it('refuses a configured ZTS binary by name', function () use ($describe, $find) {
    (new PhpFinder('/opt/zts/php', $describe(['/opt/zts/php' => ['zts' => true, 'version' => '8.4.25', 'extension_dir' => '/z']]), $find([])))->nts();
})->throws(RuntimeException::class, '/opt/zts/php is ZTS');

it('searches php, php8.4 and php84 on PATH and skips ZTS ones', function () use ($describe, $find) {
    $finder = new PhpFinder(null, $describe([
        '/herd/php' => ['zts' => true, 'version' => '8.4.1', 'extension_dir' => '/h'],
        '/brew/php84' => ['zts' => false, 'version' => '8.4.25', 'extension_dir' => '/b'],
    ]), $find(['php' => '/herd/php', 'php84' => '/brew/php84']));

    expect($finder->nts()['binary'])->toBe('/brew/php84');
});

it('says what it looked for when nothing fits', function () use ($describe, $find) {
    (new PhpFinder(null, $describe([]), $find([])))->nts();
})->throws(RuntimeException::class, 'php, php8.4, php84');

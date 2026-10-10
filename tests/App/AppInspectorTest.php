<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\AppInspector;
use Venusian\Build\App\Manifest;

/*
 * venusian build runs inside a Venusian app. The inspector names each
 * missing piece; the manifest is the build's view of the app.
 */
it('names each missing piece of a Venusian app', function () {
    $dir = sys_get_temp_dir().'/venusian-inspect-'.bin2hex(random_bytes(4));
    mkdir($dir);

    expect((new AppInspector($dir))->problems())->toBe([
        'composer.json does not require venusian/framework',
        'computer is missing',
        'bootstrap/app.php is missing',
    ]);

    file_put_contents($dir.'/composer.json', '{"require":{"venusian/framework":"^0.10"}}');
    touch($dir.'/computer');
    mkdir($dir.'/bootstrap');
    touch($dir.'/bootstrap/app.php');

    expect((new AppInspector($dir))->problems())->toBe(['vendor/autoload.php is missing: run composer install first']);

    mkdir($dir.'/vendor');
    touch($dir.'/vendor/autoload.php');

    expect((new AppInspector($dir))->problems())->toBe([]);

    (new Filesystem)->remove($dir);
});

it('reads a manifest and overrides fields', function () {
    $manifest = Manifest::fromJson('{"name":"Stargazer","id":"com.venusian.stargazer","version":"0.1.0","sketch":null,"sketches":["stargazer"],"icon":null,"extensions":["appkit"],"targets":["macos-arm64"],"base_path":"/app"}');

    expect($manifest->sketch)->toBeNull()
        ->and($manifest->with(['sketch' => 'stargazer', 'version' => '1.0.0'])->sketch)->toBe('stargazer')
        ->and($manifest->with(['version' => '1.0.0'])->version)->toBe('1.0.0')
        ->and($manifest->with(['version' => '1.0.0'])->name)->toBe('Stargazer')
        ->and($manifest->kebab())->toBe('stargazer');
});

it('kebabs the name for file names', function () {
    $manifest = Manifest::fromJson('{"name":"Star  Gazer 2!","id":"x","version":"1","sketch":"s","sketches":[],"icon":null,"extensions":[],"targets":[],"base_path":"/app"}');

    expect($manifest->kebab())->toBe('star-gazer-2');
});

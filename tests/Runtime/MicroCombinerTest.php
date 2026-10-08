<?php

use Venusian\Build\Runtime\MicroCombiner;
use Venusian\Build\Runtime\MicroIni;

/*
 * The ini block phpmicro reads sits between micro.sfx and the payload:
 * magic bytes, big-endian length, the ini text. Every line survives,
 * repeated extension= keys included.
 */
it('keeps every extension line and the settings', function () {
    $ini = new MicroIni('lib', ['appkit.so', 'kqueue.so'], ['memory_limit' => '512M']);

    expect($ini->render())->toBe("memory_limit=512M\nextension_dir=lib\nextension=appkit.so\nextension=kqueue.so\n");
});

it('writes sfx, the magic ini block and the phar in order, executable', function () {
    $dir = sys_get_temp_dir().'/venusian-combine-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/micro.sfx', 'SFX');
    file_put_contents($dir.'/app.phar', 'PHAR');

    (new MicroCombiner)->combine($dir.'/micro.sfx', new MicroIni('lib', ['a.so'], []), $dir.'/app.phar', $dir.'/out');

    $ini = "extension_dir=lib\nextension=a.so\n";
    expect(file_get_contents($dir.'/out'))->toBe('SFX'."\xfd\xf6\x69\xe6".pack('N', strlen($ini)).$ini.'PHAR')
        ->and(is_executable($dir.'/out'))->toBeTrue();

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

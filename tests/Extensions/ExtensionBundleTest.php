<?php

use Venusian\Build\Extensions\ExtensionBundle;

/*
 * Which .so files ship: every wanted extension the runtime lacks, found in
 * the extension source PHP's extension_dir.
 */
it('resolves every extension the runtime lacks to a .so in the extension dir', function () {
    $dir = sys_get_temp_dir().'/venusian-ext-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/appkit.so');
    touch($dir.'/kqueue.so');

    $files = (new ExtensionBundle(['appkit', 'kqueue', 'mbstring', 'json'], ['Core', 'json', 'mbstring'], $dir))->files();

    expect($files)->toBe(['appkit' => $dir.'/appkit.so', 'kqueue' => $dir.'/kqueue.so']);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('names the extension and the directory when a .so is missing', function () {
    (new ExtensionBundle(['imgdec'], ['Core'], '/nowhere/ext'))->files();
})->throws(RuntimeException::class, 'imgdec.so not found in /nowhere/ext');

it('compares names case-insensitively, as PHP does', function () {
    expect((new ExtensionBundle(['PDO', 'phar'], ['pdo', 'Phar'], '/x'))->files())->toBe([]);
});

it('ships optional extensions when the PHP has them and skips the ones it lacks', function () {
    $dir = sys_get_temp_dir().'/venusian-ext-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/appkit.so');
    touch($dir.'/kqueue.so');

    $files = (new ExtensionBundle(['appkit'], ['Core'], $dir, ['kqueue', 'pcurl', 'appkit']))->files();

    expect($files)->toBe(['appkit' => $dir.'/appkit.so', 'kqueue' => $dir.'/kqueue.so']);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

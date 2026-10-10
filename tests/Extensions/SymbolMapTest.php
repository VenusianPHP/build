<?php

use Venusian\Build\Extensions\SymbolMap;

/* The symbol map: each loaded extension's functions and classes, lower-cased, never the always-on ones. */
it('maps a loaded extension\'s functions and classes, and leaves out the always-on ones', function () {
    $map = SymbolMap::fromRunningPhp();

    expect($map->functions['ctype_digit'] ?? null)->toBe('ctype')
        ->and($map->classes['domdocument'] ?? null)->toBe('dom')
        ->and($map->classes['pdo'] ?? null)->toBe('pdo')
        ->and($map->functions)->not->toHaveKey('strlen')
        ->and($map->classes)->not->toHaveKey('arrayobject');
});

it('lets the merged map win and round-trips through JSON', function () {
    $file = tempnam(sys_get_temp_dir(), 'symbols');
    file_put_contents($file, (new SymbolMap(['a_fn' => 'old'], ['a' => 'old']))->toJson());

    $map = SymbolMap::fromFile($file)->merge(new SymbolMap(['a_fn' => 'new', 'b_fn' => 'b'], []));
    unlink($file);

    expect($map->functions)->toBe(['a_fn' => 'new', 'b_fn' => 'b'])->and($map->classes)->toBe(['a' => 'old']);
});

it('ships a map that names the catalog', function () {
    $map = SymbolMap::shipped();

    expect(array_unique([...array_values($map->functions), ...array_values($map->classes)]))
        ->toContain('dom', 'pdo_sqlite', 'imgdec', 'rasterize', 'appkit', 'kqueue', 'epoll', 'gtk', 'qt', 'glfw', 'sdl3', 'vulkan', 'pcurl');
});

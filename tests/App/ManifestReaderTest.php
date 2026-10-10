<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\ManifestReader;

/*
 * The manifest comes from the app's own files: build.json over the
 * defaults, app.name and app.id from config/app.php, sketches from the class
 * files, extensions from composer.lock. No framework boot, no computer command.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-manifest-'.bin2hex(random_bytes(4));
    (new Filesystem)->mirror(__DIR__.'/../Fixtures/app', $this->root);
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('reads name, id, version, sketch and extensions', function () {
    $manifest = (new ManifestReader($this->root))->read();

    expect($manifest->name)->toBe('Star Gazer')
        ->and($manifest->id)->toBe('com.venusian.app')
        ->and($manifest->version)->toBe('2.0.0')
        ->and($manifest->sketch)->toBe('stargazer')
        ->and($manifest->sketches)->toBe(['stargazer'])
        ->and($manifest->extensions)->toBe(['appkit', 'ctype', 'imgdec', 'mbstring'])
        ->and($manifest->build)->toBe(1)
        ->and($manifest->permissions)->toBe([])
        ->and($manifest->targets)->toBe([])
        ->and($manifest->windowed)->toBeTrue()
        ->and($manifest->base_path)->toBe($this->root);
});

it('falls back to every default without build.json, and takes APP_NAME and APP_ID from .env', function () {
    unlink($this->root.'/build.json');
    file_put_contents($this->root.'/.env', "APP_NAME=Stargazer\nAPP_ID=com.venusian.stargazer\nAPP_ENV=local\n");

    $manifest = (new ManifestReader($this->root))->read();

    expect($manifest->name)->toBe('Stargazer')
        ->and($manifest->id)->toBe('com.venusian.stargazer')
        ->and($manifest->version)->toBe('0.1.0')
        ->and($manifest->extensions)->toBe(['appkit', 'ctype', 'mbstring'])
        ->and($manifest->icon)->toBeNull()
        ->and($manifest->category)->toBe('Utility');
});

it('takes a build.json id that matches app.id', function () {
    file_put_contents($this->root.'/.env', "APP_ID=com.venusian.stargazer\n");
    file_put_contents($this->root.'/build.json', '{"id": "com.venusian.stargazer"}');

    expect((new ManifestReader($this->root))->read()->id)->toBe('com.venusian.stargazer');
});

it('throws when build.json and app.id disagree, in the words agreed', function () {
    file_put_contents($this->root.'/.env', "APP_ID=com.venusian.stargazer\n");
    file_put_contents($this->root.'/build.json', '{"id": "org.example.other"}');

    (new ManifestReader($this->root))->read();
})->throws(RuntimeException::class, "build.json names org.example.other but config/app.php resolves app.id to com.venusian.stargazer; make them match, the toolkit engines get pissy when app ids don't match.");

it('tells an app without app.id to add the key', function () {
    file_put_contents($this->root.'/config/app.php', "<?php\n\nreturn ['name' => env('APP_NAME', 'Star Gazer')];\n");

    (new ManifestReader($this->root))->read();
})->throws(RuntimeException::class, "config/app.php has no app.id; add 'id' => env('APP_ID', 'com.venusian.app') under name (framework 0.10 ships it).");

it('carries the app\'s .env, build.env over it, without env_except keys, and APP_ID always', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Stargazer\nTOOLKIT_BRIDGE=gtk\nNASA_API_KEY=abc\n# comment\nDB_PASSWORD=\"hunter 2\"\n");
    file_put_contents($this->root.'/build.json', '{"env": {"TOOLKIT_BRIDGE": "appkit"}, "env_except": ["DB_PASSWORD", "APP_ID"]}');

    expect((new ManifestReader($this->root))->read()->env)->toBe(['APP_NAME' => 'Stargazer', 'TOOLKIT_BRIDGE' => 'appkit', 'NASA_API_KEY' => 'abc', 'APP_ID' => 'com.venusian.app']);
});

it('is not windowed without a jovian package in the lock', function () {
    $lock = json_decode(file_get_contents($this->root.'/composer.lock'), true);
    $lock['packages'] = array_values(array_filter($lock['packages'], fn (array $p): bool => ! str_starts_with($p['name'], 'jovian/')));
    file_put_contents($this->root.'/composer.lock', json_encode($lock));

    expect((new ManifestReader($this->root))->read()->windowed)->toBeFalse();
});

it('carries summary, description, author, homepage, license, category and zts from build.json', function () {
    file_put_contents($this->root.'/build.json', json_encode(['summary' => 'Sky', 'description' => "A\n\nB", 'author' => 'A <a@b.c>', 'homepage' => 'https://x', 'license' => 'MIT', 'category' => 'Education', 'zts' => true]));

    $manifest = (new ManifestReader($this->root))->read();

    expect([$manifest->summary, $manifest->description, $manifest->author, $manifest->homepage, $manifest->license, $manifest->category, $manifest->zts])
        ->toBe(['Sky', "A\n\nB", 'A <a@b.c>', 'https://x', 'MIT', 'Education', true]);
});

it('takes the app\'s own ext-* requirements from the lock\'s platform list', function () {
    $lock = json_decode(file_get_contents($this->root.'/composer.lock'), true);
    $lock['platform'] = ['php' => '^8.4', 'ext-pdo_sqlite' => '*', 'ext-DOM' => '*'];
    file_put_contents($this->root.'/composer.lock', json_encode($lock));

    expect((new ManifestReader($this->root))->read()->extensions)->toBe(['appkit', 'ctype', 'dom', 'imgdec', 'mbstring', 'pdo_sqlite']);
});

it('reads the default database connection, its sqlite path from database_path()', function () {
    file_put_contents($this->root.'/config/database.php', "<?php return ['default' => env('DB_CONNECTION', 'sqlite'), 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => env('DB_DATABASE', database_path('database.sqlite'))], 'mysql' => ['driver' => 'mysql', 'database' => 'app']]];");

    expect((new ManifestReader($this->root))->read()->database)->toBe(['driver' => 'sqlite', 'database' => $this->root.'/database/database.sqlite']);
});

it('has no database without config/database.php', function () {
    expect((new ManifestReader($this->root))->read()->database)->toBeNull();
});

it('resolves the database with build.json env over .env, as the packaged .env will have it', function () {
    file_put_contents($this->root.'/config/database.php', "<?php return ['default' => env('DB_CONNECTION', 'sqlite'), 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => env('DB_DATABASE', database_path('database.sqlite'))], 'mysql' => ['driver' => 'mysql', 'database' => 'app']]];");
    file_put_contents($this->root.'/.env', "DB_CONNECTION=mysql\n", FILE_APPEND);
    $build = json_decode(file_get_contents($this->root.'/build.json'), true);
    $build['env'] = ['DB_CONNECTION' => 'sqlite'];
    file_put_contents($this->root.'/build.json', json_encode($build));

    expect((new ManifestReader($this->root))->read()->database)->toBe(['driver' => 'sqlite', 'database' => $this->root.'/database/database.sqlite']);
});

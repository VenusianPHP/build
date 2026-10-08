<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\ManifestReader;

/*
 * The manifest comes from the app's own files: config/build.php over the
 * defaults, app.name from config/app.php, sketches from the class files,
 * extensions from composer.lock. No framework boot, no computer command.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-manifest-'.bin2hex(random_bytes(4));
    (new Filesystem)->mirror(__DIR__.'/../Fixtures/app', $this->root);
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('reads name, version, sketch, extensions and derives the bundle id', function () {
    $manifest = (new ManifestReader($this->root))->read();

    expect($manifest->name)->toBe('Star Gazer')
        ->and($manifest->bundle_id)->toBe('com.venusian.star-gazer')
        ->and($manifest->version)->toBe('2.0.0')
        ->and($manifest->sketch)->toBe('stargazer')
        ->and($manifest->sketches)->toBe(['stargazer'])
        ->and($manifest->extensions)->toBe(['appkit', 'ctype', 'imgdec', 'mbstring'])
        ->and($manifest->repository)->toBe('phpacker/php-bin')
        ->and($manifest->sign)->toBe('adhoc')
        ->and($manifest->targets)->toBe(['macos-arm64'])
        ->and($manifest->base_path)->toBe($this->root);
});

it('falls back to every default without config/build.php, and takes APP_NAME from .env', function () {
    unlink($this->root.'/config/build.php');
    file_put_contents($this->root.'/.env', "APP_NAME=Stargazer\nAPP_ENV=local\n");

    $manifest = (new ManifestReader($this->root))->read();

    expect($manifest->name)->toBe('Stargazer')
        ->and($manifest->version)->toBe('0.1.0')
        ->and($manifest->extensions)->toBe(['appkit', 'ctype', 'mbstring'])
        ->and($manifest->icon)->toBeNull()
        ->and($manifest->php)->toBeNull();
});

it('carries build.env for the packaged app', function () {
    file_put_contents($this->root.'/config/build.php', "<?php\n\nreturn ['env' => ['TOOLKIT_BRIDGE' => 'appkit']];\n");

    expect((new ManifestReader($this->root))->read()->env)->toBe(['TOOLKIT_BRIDGE' => 'appkit']);
});

it('leaves the sketch open when there are several', function () {
    file_put_contents($this->root.'/app/Runner/Sketches/Weather.php', "<?php\n\nnamespace App\\Runner\\Sketches;\n\nclass Weather extends Sketch {}\n");

    $manifest = (new ManifestReader($this->root))->read();

    expect($manifest->sketch)->toBeNull()
        ->and($manifest->sketches)->toBe(['stargazer', 'weather']);
});

it('honours a sketch name set with the attribute', function () {
    file_put_contents($this->root.'/app/Runner/Sketches/Stargazer.php', "<?php\n\nnamespace App\\Runner\\Sketches;\n\nuse Voyager\\Sketches\\Attributes\\Sketch as SketchName;\n\n#[SketchName(name: 'sky')]\nclass Stargazer extends Sketch {}\n");

    expect((new ManifestReader($this->root))->read()->sketches)->toBe(['sky']);
});

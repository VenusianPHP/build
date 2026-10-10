<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\BuildJson;

/*
 * build.json: the app's build facts, every key defaulted, unknown keys and
 * bad values refused by name, answers written back without losing keys.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-buildjson-'.bin2hex(random_bytes(4));
    mkdir($this->root);
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('answers every default without a file', function () {
    $values = (new BuildJson($this->root))->read();

    expect($values)->toBe(BuildJson::DEFAULTS);
});

it('merges the file over the defaults and tolerates $schema', function () {
    file_put_contents($this->root.'/build.json', '{"$schema": "x", "id": "com.venusian.stargazer", "version": "1.2.0", "targets": ["linux-arm64"]}');

    $values = (new BuildJson($this->root))->read();

    expect($values['id'])->toBe('com.venusian.stargazer')
        ->and($values['version'])->toBe('1.2.0')
        ->and($values['targets'])->toBe(['linux-arm64'])
        ->and($values['build'])->toBe(1)
        ->and($values['permissions'])->toBe([]);
});

it('refuses an unknown key by name', function () {
    file_put_contents($this->root.'/build.json', '{"extentions": ["gtk"]}');

    (new BuildJson($this->root))->read();
})->throws(RuntimeException::class, 'build.json has no key "extentions"');

it('refuses an id that is not reverse-DNS', function () {
    file_put_contents($this->root.'/build.json', '{"id": "stargazer"}');

    (new BuildJson($this->root))->read();
})->throws(RuntimeException::class, 'build.json id "stargazer" must be reverse-DNS');

it('refuses a target it does not know', function () {
    file_put_contents($this->root.'/build.json', '{"targets": ["windows-x86_64"]}');

    (new BuildJson($this->root))->read();
})->throws(RuntimeException::class, 'build.json targets: windows-x86_64 is not one of macos-arm64, linux-arm64, linux-x86_64');

it('refuses a version Debian would not take', function () {
    file_put_contents($this->root.'/build.json', '{"version": "v1"}');

    (new BuildJson($this->root))->read();
})->throws(RuntimeException::class, 'build.json version "v1" must start with a digit');

it('refuses invalid JSON with the parser message', function () {
    file_put_contents($this->root.'/build.json', '{"id": ');

    (new BuildJson($this->root))->read();
})->throws(RuntimeException::class, 'build.json is not valid JSON');

it('writes answers back, keeping keys it was not given, pretty and with the schema line first', function () {
    file_put_contents($this->root.'/build.json', '{"targets": ["linux-arm64"], "env": {"A": "1"}}');

    (new BuildJson($this->root))->write(['id' => 'com.venusian.stargazer', 'version' => '2.0.0']);

    $json = json_decode(file_get_contents($this->root.'/build.json'), true);
    expect(array_keys($json))->toBe(['$schema', 'id', 'version', 'targets', 'env'])
        ->and($json['$schema'])->toBe(BuildJson::SCHEMA)
        ->and($json['env'])->toBe(['A' => '1'])
        ->and(file_get_contents($this->root.'/build.json'))->toContain("\n    \"id\": \"com.venusian.stargazer\",\n");
});

it('creates the file on write when there was none', function () {
    (new BuildJson($this->root))->write(['name' => 'Stargazer']);

    expect(json_decode(file_get_contents($this->root.'/build.json'), true)['name'])->toBe('Stargazer');
});

it('says where sign, php and repository went', function (string $key, string $message) {
    file_put_contents($this->root.'/build.json', json_encode([$key => 'x']));

    expect(fn () => (new BuildJson($this->root))->read())->toThrow(RuntimeException::class, $message);
})->with([
    ['sign', 'build.json sign moved to ~/.venusian/build/config.json as macos.sign'],
    ['php', 'build.json php is gone: macOS builds compile their PHP'],
    ['repository', 'build.json repository is gone: macOS builds compile their PHP'],
]);

it('takes a build number and permissions', function () {
    file_put_contents($this->root.'/build.json', '{"build": 42, "permissions": {"camera": "Shows the sky through the webcam"}}');

    $values = (new BuildJson($this->root))->read();

    expect($values['build'])->toBe(42)->and($values['permissions'])->toBe(['camera' => 'Shows the sky through the webcam']);
});

it('refuses a bad build number, permission or category by name', function (string $json, string $message) {
    file_put_contents($this->root.'/build.json', $json);

    expect(fn () => (new BuildJson($this->root))->read())->toThrow(RuntimeException::class, $message);
})->with([
    ['{"build": 0}', 'build.json build must be a whole number of at least 1.'],
    ['{"build": "42"}', 'build.json build must be a whole number of at least 1.'],
    ['{"permissions": ["camera"]}', 'build.json permissions must be an object of permission: the sentence macOS shows when it asks.'],
    ['{"permissions": {"location": "x"}}', 'build.json permissions: location is not one of camera, microphone, bluetooth.'],
    ['{"permissions": {"camera": ""}}', 'build.json permissions: camera needs the sentence macOS shows when it asks.'],
    ['{"category": "Games"}', 'build.json category Games is not a freedesktop main category: AudioVideo, Development, Education, Game, Graphics, Network, Office, Science, Settings, System, Utility.'],
]);

it('has a schema that describes exactly the keys it reads', function () {
    $schema = json_decode(file_get_contents(__DIR__.'/../../schema/build.schema.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($schema['properties']))->toEqualCanonicalizing(['$schema', ...array_keys(BuildJson::DEFAULTS)])
        ->and($schema['properties']['category']['enum'])->toBe(BuildJson::CATEGORIES)
        ->and(array_keys($schema['properties']['permissions']['properties']))->toBe(BuildJson::PERMISSIONS);
});

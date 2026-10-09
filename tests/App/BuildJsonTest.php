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
        ->and($values['sign'])->toBe('adhoc');
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

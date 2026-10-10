<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\Hosts\UserConfig;

/* ~/.venusian/build/config.json: which docker context builds which Linux target. */
beforeEach(function () {
    $this->home = sys_get_temp_dir().'/venusian-home-'.bin2hex(random_bytes(4));
    mkdir($this->home.'/.venusian/build', 0777, true);
});

afterEach(function () {
    (new Filesystem)->remove($this->home);
});

it('answers no hosts without a file', function () {
    expect((new UserConfig($this->home))->hosts())->toBe([]);
});

it('reads the hosts map', function () {
    file_put_contents($this->home.'/.venusian/build/config.json', '{"hosts": {"linux-x86_64": "gamingpc", "linux-arm64": "jetson"}}');

    expect((new UserConfig($this->home))->hosts())->toBe(['linux-x86_64' => 'gamingpc', 'linux-arm64' => 'jetson']);
});

it('refuses a host for a target it does not know', function () {
    file_put_contents($this->home.'/.venusian/build/config.json', '{"hosts": {"linux-riscv64": "cm4"}}');

    (new UserConfig($this->home))->hosts();
})->throws(RuntimeException::class, 'config.json hosts: linux-riscv64 is not one of macos-arm64, linux-arm64, linux-x86_64');

it('signs ad hoc until the machine says otherwise', function () {
    $config = new UserConfig($this->home);

    expect($config->hasMacos())->toBeFalse()
        ->and($config->macos())->toBe(['sign' => 'adhoc', 'notary_profile' => null]);
});

it('saves how macOS builds sign beside the hosts', function () {
    file_put_contents($this->home.'/.venusian/build/config.json', '{"hosts": {"linux-x86_64": "gamingpc"}}');
    $config = new UserConfig($this->home);

    $config->saveMacos('Developer ID Application: A (T)', 'venusian');

    expect($config->hosts())->toBe(['linux-x86_64' => 'gamingpc'])
        ->and($config->hasMacos())->toBeTrue()
        ->and($config->macos())->toBe(['sign' => 'Developer ID Application: A (T)', 'notary_profile' => 'venusian']);

    $config->saveMacos('adhoc', null);

    expect(json_decode(file_get_contents($this->home.'/.venusian/build/config.json'), true)['macos'])->toBe(['sign' => 'adhoc']);
});

it('creates the config when the machine has none', function () {
    $home = $this->home.'/fresh';

    (new UserConfig($home))->saveMacos('adhoc', null);

    expect((new UserConfig($home))->hasMacos())->toBeTrue();
});

it('refuses an empty signing identity or profile', function (string $json, string $message) {
    file_put_contents($this->home.'/.venusian/build/config.json', $json);

    expect(fn () => (new UserConfig($this->home))->macos())->toThrow(RuntimeException::class, $message);
})->with([
    ['{"macos": {"sign": ""}}', 'config.json macos.sign must be "adhoc" or a codesign identity'],
    ['{"macos": {"sign": "adhoc", "notary_profile": ""}}', 'config.json macos.notary_profile must name a notarytool keychain profile'],
]);

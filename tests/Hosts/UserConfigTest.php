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

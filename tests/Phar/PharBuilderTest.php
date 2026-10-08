<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Venusian\Build\App\Manifest;
use Venusian\Build\Phar\PharBuilder;

/*
 * The phar holds the app and its production vendor; its stub runs rocket
 * with the chosen sketch. Built and run in child processes, since this
 * process has phar.readonly on.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-phar-'.bin2hex(random_bytes(6));
    (new Filesystem)->mirror(__DIR__.'/../Fixtures/app', $this->root.'/app');
    // The fixture lock names packages; composer install would fetch them. This copy's lock names none.
    file_put_contents($this->root.'/app/composer.json', json_encode(['name' => 'venusian-tests/build-fixture', 'type' => 'project', 'require' => ['php' => '^8.4'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($this->root.'/app/composer.lock', json_encode([
        '_readme' => [], 'content-hash' => md5('fixture'), 'packages' => [], 'packages-dev' => [],
        'aliases' => [], 'minimum-stability' => 'stable', 'stability-flags' => [], 'prefer-stable' => false,
        'prefer-lowest' => false, 'platform' => [], 'platform-dev' => [], 'plugin-api-version' => '2.6.0',
    ]));
    file_put_contents($this->root.'/app/.env', 'SECRET=1');
    mkdir($this->root.'/app/vendor/dev-only', 0777, true);
    file_put_contents($this->root.'/app/vendor/dev-only/leftover.php', '<?php');
    mkdir($this->root.'/app/bootstrap/cache', 0777, true);
    file_put_contents($this->root.'/app/bootstrap/cache/packages.php', '<?php return [];');
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('builds a phar that runs rocket with the sketch and carries the metadata', function () {
    $manifest = Manifest::fromJson(json_encode([
        'name' => 'Probe', 'bundle_id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
        'icon' => null, 'extensions' => [], 'php' => null, 'repository' => 'phpacker/php-bin', 'sign' => 'adhoc', 'targets' => ['macos-arm64'], 'base_path' => $this->root.'/app',
    ]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $phar = new Phar($this->root.'/out.phar');
    expect($phar->getMetadata())->toBe(['name' => 'Probe', 'bundle_id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => 'stargazer'])
        ->and(isset($phar['.env']))->toBeFalse()
        ->and(isset($phar['vendor/dev-only/leftover.php']))->toBeFalse()
        ->and(isset($phar['bootstrap/cache/packages.php']))->toBeFalse()
        ->and(isset($phar['vendor/autoload.php']))->toBeTrue()
        ->and(isset($phar['database/database.sqlite']))->toBeTrue()
        ->and(isset($phar['storage/logs/.gitignore']))->toBeTrue()
        ->and(isset($phar['storage/framework/cache/data/.gitignore']))->toBeTrue()
        ->and(isset($phar['config/build.php']))->toBeTrue();

    $run = new Process([PHP_BINARY, $this->root.'/out.phar', '--flag']);
    $run->mustRun();
    $report = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['argv'])->toBe(['rocket', 'stargazer', '--flag'])
        ->and($report['dir'])->toBe('phar://'.realpath($this->root).'/out.phar')
        ->and($report['autoload'])->toBeTrue();
});

it('writes only the env the manifest names, never the developer\'s .env', function () {
    $manifest = Manifest::fromJson(json_encode([
        'name' => 'Probe', 'bundle_id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
        'icon' => null, 'extensions' => [], 'php' => null, 'repository' => 'phpacker/php-bin', 'sign' => 'adhoc', 'targets' => ['macos-arm64'], 'base_path' => $this->root.'/app',
        'env' => ['TOOLKIT_BRIDGE' => 'appkit', 'APP_NAME' => 'Star Gazer'],
    ]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    expect(file_get_contents('phar://'.$this->root.'/out.phar/.env'))->toBe("TOOLKIT_BRIDGE=appkit\nAPP_NAME=\"Star Gazer\"\n");
});

it('refuses a manifest without a sketch', function () {
    $manifest = Manifest::fromJson(json_encode([
        'name' => 'Probe', 'bundle_id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => null, 'sketches' => ['a', 'b'],
        'icon' => null, 'extensions' => [], 'php' => null, 'repository' => 'phpacker/php-bin', 'sign' => 'adhoc', 'targets' => [], 'base_path' => $this->root.'/app',
    ]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');
})->throws(RuntimeException::class, 'sketch');

<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
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
    // The fixture lock names packages composer would fetch; this copy requires none and locks fresh.
    file_put_contents($this->root.'/app/composer.json', json_encode(['name' => 'venusian-tests/build-fixture', 'type' => 'project', 'require' => ['php' => '^8.4'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    (new Process([PHP_BINARY, (new ExecutableFinder)->find('composer'), 'update', '--no-install', '--no-interaction', '--quiet'], $this->root.'/app'))->mustRun();
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
        'name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
        'icon' => null, 'extensions' => [], 'targets' => ['macos-arm64'], 'base_path' => $this->root.'/app',
    ]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $phar = new Phar($this->root.'/out.phar');
    expect($phar->getMetadata())->toBe(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => 'stargazer'])
        ->and(isset($phar['.env']))->toBeFalse()
        ->and(isset($phar['vendor/dev-only/leftover.php']))->toBeFalse()
        ->and(isset($phar['bootstrap/cache/packages.php']))->toBeFalse()
        ->and(isset($phar['vendor/autoload.php']))->toBeTrue()
        ->and(isset($phar['database/database.sqlite']))->toBeTrue()
        ->and(isset($phar['storage/logs/.gitignore']))->toBeTrue()
        ->and(isset($phar['storage/framework/cache/data/.gitignore']))->toBeTrue()
        ->and(isset($phar['build.json']))->toBeTrue();

    $run = new Process([PHP_BINARY, $this->root.'/out.phar', '--flag']);
    $run->mustRun();
    $report = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['argv'])->toBe(['rocket', 'stargazer', '--flag'])
        ->and($report['dir'])->toBe('phar://'.realpath($this->root).'/out.phar')
        ->and($report['autoload'])->toBeTrue();
});

it('runs a script inside itself when named as the first argument, the way php <script> would', function () {
    file_put_contents($this->root.'/app/worker-probe.php', '<?php echo json_encode(["argv" => $argv, "server" => $_SERVER["argv"]]);');
    $manifest = Manifest::fromJson(json_encode([
        'name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
        'icon' => null, 'extensions' => [], 'targets' => ['macos-arm64'], 'base_path' => $this->root.'/app',
    ]));
    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');
    $script = 'phar://'.realpath($this->root).'/out.phar/worker-probe.php';

    $run = new Process([PHP_BINARY, $this->root.'/out.phar', $script, 'autoload.php', '/base']);
    $run->mustRun();
    $report = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['argv'])->toBe([$script, 'autoload.php', '/base'])
        ->and($report['server'])->toBe([$script, 'autoload.php', '/base']);
});

it('writes only the env the manifest names, never the developer\'s .env', function () {
    $manifest = Manifest::fromJson(json_encode([
        'name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
        'icon' => null, 'extensions' => [], 'targets' => ['macos-arm64'], 'base_path' => $this->root.'/app',
        'env' => ['TOOLKIT_BRIDGE' => 'appkit', 'APP_NAME' => 'Star Gazer'],
    ]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    expect(file_get_contents('phar://'.$this->root.'/out.phar/.env'))->toBe("TOOLKIT_BRIDGE=appkit\nAPP_NAME=\"Star Gazer\"\n");
});

it('fails with composer\'s own message when the lock lacks a required package', function () {
    file_put_contents($this->root.'/app/composer.json', json_encode(['name' => 'venusian-tests/build-fixture', 'type' => 'project', 'require' => ['php' => '^8.4', 'venusian-tests/absent' => '*'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $manifest = Manifest::fromJson(json_encode([
        'name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
        'icon' => null, 'extensions' => [], 'targets' => ['macos-arm64'], 'base_path' => $this->root.'/app',
    ]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');
})->throws(RuntimeException::class, 'venusian-tests/absent');

it('refuses a manifest without a sketch', function () {
    $manifest = Manifest::fromJson(json_encode([
        'name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.2.3', 'sketch' => null, 'sketches' => ['a', 'b'],
        'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app',
    ]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');
})->throws(RuntimeException::class, 'sketch');

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

/** A path-repository package in its own git repository, published with tests and docs export-ignored. */
function pathPackage(string $dir): void
{
    $files = new Filesystem;
    $files->dumpFile("{$dir}/composer.json", json_encode(['name' => 'venusian-tests/pkg', 'version' => '1.0.0', 'autoload' => ['psr-4' => ['Pkg\\' => 'src/']]]));
    $files->dumpFile("{$dir}/src/Thing.php", '<?php namespace Pkg; final class Thing {}');
    $files->dumpFile("{$dir}/tests/ThingTest.php", '<?php');
    $files->dumpFile("{$dir}/docs/guide.md", '# guide');
    $files->dumpFile("{$dir}/untracked.php", '<?php');
    $files->dumpFile("{$dir}/ignored.txt", 'secret');
    $files->dumpFile("{$dir}/vendor/junk.php", '<?php');
    $files->dumpFile("{$dir}/.gitignore", "/vendor\n/ignored.txt\n");
    $files->dumpFile("{$dir}/.gitattributes", "/tests export-ignore\n/docs export-ignore\n");
    $git = fn (string ...$args) => (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $dir))->mustRun();
    $git('init', '-q');
    $git('add', 'composer.json', 'src', 'tests', 'docs', '.gitignore', '.gitattributes');
    $git('commit', '-q', '-m', 'x');
}

it('ships a path-repository package as git would publish it and leaves its source tree alone', function (string $url) {
    pathPackage($this->root.'/pkg');
    file_put_contents($this->root.'/app/composer.json', json_encode([
        'name' => 'venusian-tests/build-fixture', 'type' => 'project', 'minimum-stability' => 'dev',
        'require' => ['php' => '^8.4', 'venusian-tests/pkg' => '*'],
        'repositories' => [['type' => 'path', 'url' => $url === 'relative' ? '../pkg' : $this->root.'/pkg', 'options' => ['symlink' => true]]],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));
    (new Process([PHP_BINARY, (new ExecutableFinder)->find('composer'), 'update', '--no-install', '--no-interaction', '--quiet'], $this->root.'/app'))->mustRun();
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $phar = new Phar($this->root.'/out.phar');
    expect(isset($phar['vendor/venusian-tests/pkg/src/Thing.php']))->toBeTrue()
        ->and(isset($phar['vendor/venusian-tests/pkg/untracked.php']))->toBeTrue()
        ->and(isset($phar['vendor/venusian-tests/pkg/tests/ThingTest.php']))->toBeFalse()
        ->and(isset($phar['vendor/venusian-tests/pkg/docs/guide.md']))->toBeFalse()
        ->and(isset($phar['vendor/venusian-tests/pkg/ignored.txt']))->toBeFalse()
        ->and(isset($phar['vendor/venusian-tests/pkg/vendor/junk.php']))->toBeFalse()
        ->and(isset($phar['vendor/venusian-tests/pkg/.git/HEAD']))->toBeFalse()
        ->and(is_file($this->root.'/pkg/src/Thing.php'))->toBeTrue()
        ->and(is_file($this->root.'/pkg/tests/ThingTest.php'))->toBeTrue()
        ->and(is_dir($this->root.'/pkg/.git'))->toBeTrue();
})->with(['absolute', 'relative']);

it('ships the app as git would publish it when the app is a repository', function () {
    file_put_contents($this->root.'/app/.gitignore', "/vendor\n/secret.txt\n");
    file_put_contents($this->root.'/app/secret.txt', 'secret');
    file_put_contents($this->root.'/app/.gitattributes', "/notes export-ignore\n");
    mkdir($this->root.'/app/notes');
    file_put_contents($this->root.'/app/notes/plan.md', '# plan');
    $git = fn (string ...$args) => (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $this->root.'/app'))->mustRun();
    $git('init', '-q');
    $git('add', '.');
    $git('commit', '-q', '-m', 'x');
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $phar = new Phar($this->root.'/out.phar');
    expect(isset($phar['secret.txt']))->toBeFalse()
        ->and(isset($phar['notes/plan.md']))->toBeFalse()
        ->and(isset($phar['.env']))->toBeFalse()
        ->and(isset($phar['app/Runner/Sketches/Stargazer.php']))->toBeTrue()
        ->and(isset($phar['vendor/autoload.php']))->toBeTrue();
});

it('ships an authoritative classmap that still finds the app\'s classes', function () {
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 'stargazer', 'sketches' => ['stargazer'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $run = new Process([PHP_BINARY, $this->root.'/out.phar']);
    $run->mustRun();
    expect(file_get_contents('phar://'.$this->root.'/out.phar/vendor/composer/autoload_real.php'))->toContain('setClassMapAuthoritative(true)')
        ->and(json_decode($run->getOutput(), true)['autoload'])->toBeTrue();
});

/** A stand-in for the framework: the kernel boots, and the migrator writes one table into DB_DATABASE. */
function fakeFramework(string $app): void
{
    file_put_contents("{$app}/bootstrap/app.php", <<<'PHP'
    <?php
    return new class {
        public function make(string $id): object
        {
            return match ($id) {
                'migrator' => new class {
                    public function repositoryExists(): bool { return false; }
                    public function getRepository(): object { return new class { public function createRepository(): void {} }; }
                    public function run(array $paths): array
                    {
                        (new PDO('sqlite:'.getenv('DB_DATABASE')))->exec('create table seeded (id integer)');
                        return array_map('basename', glob($paths[0].'/*.php'));
                    }
                },
                default => new class { public function bootstrap(): void {} },
            };
        }
    };
    PHP);
}

it('ships a fresh database migrated from database/migrations, never the developer\'s', function () {
    fakeFramework($this->root.'/app');
    file_put_contents($this->root.'/app/database/database.sqlite', 'DEVELOPER DATA');
    mkdir($this->root.'/app/database/migrations');
    file_put_contents($this->root.'/app/database/migrations/2026_01_01_000000_create_seeded.php', '<?php');
    $lines = [];
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app',
        'database' => ['driver' => 'sqlite', 'database' => $this->root.'/app/database/database.sqlite']]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar', function (string $line) use (&$lines): void { $lines[] = $line; });

    $copy = $this->root.'/shipped.sqlite';
    copy('phar://'.$this->root.'/out.phar/database/database.sqlite', $copy);
    $tables = (new PDO('sqlite:'.$copy))->query("select name from sqlite_master where type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toBe(['seeded'])
        ->and(file_get_contents($copy))->not->toContain('DEVELOPER DATA')
        ->and($lines)->toContain('Seeding database/database.sqlite from 1 migration')
        ->and(file_get_contents($this->root.'/app/database/database.sqlite'))->toBe('DEVELOPER DATA');
});

it('says why it seeds nothing when the sqlite file lives outside the app\'s database/', function () {
    $lines = [];
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app',
        'database' => ['driver' => 'sqlite', 'database' => '/srv/shared/app.sqlite']]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar', function (string $line) use (&$lines): void { $lines[] = $line; });

    expect($lines)->toContain("Not seeding a database: config/database.php's sqlite file is /srv/shared/app.sqlite, not the app's database/database.sqlite");
});

it('packs the boot check', function () {
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    expect(file_get_contents('phar://'.$this->root.'/out.phar/'.PharBuilder::BOOT_CHECK))->toBe(file_get_contents(__DIR__.'/../../src/Phar/boot-check.php'));
});

it('fails when the migrations print no count, even when the process exits 0', function () {
    file_put_contents($this->root.'/app/bootstrap/app.php', "<?php echo \"The bootstrap/cache directory must be present and writable.\\n\"; exit(0);");
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app',
        'database' => ['driver' => 'sqlite', 'database' => $this->root.'/app/database/database.sqlite']]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');
})->throws(RuntimeException::class, "Migrating the packaged database failed:\nThe bootstrap/cache directory must be present and writable.");

it('gives the migrations a writable bootstrap/cache and ships none of what they cache there', function () {
    fakeFramework($this->root.'/app');
    $app = file_get_contents($this->root.'/app/bootstrap/app.php');
    file_put_contents($this->root.'/app/bootstrap/app.php', str_replace("<?php\n", "<?php\nif (! is_writable(__DIR__.'/cache')) { echo \"no cache dir\\n\"; exit(0); }\nfile_put_contents(__DIR__.'/cache/services.php', '<?php return [];');\n", $app));
    mkdir($this->root.'/app/database/migrations');
    file_put_contents($this->root.'/app/database/migrations/2026_01_01_000000_create_seeded.php', '<?php');
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app',
        'database' => ['driver' => 'sqlite', 'database' => $this->root.'/app/database/database.sqlite']]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $phar = new Phar($this->root.'/out.phar');
    expect(isset($phar['bootstrap/cache/services.php']))->toBeFalse()
        ->and(isset($phar['database/database.sqlite']))->toBeTrue();
});

it('packs the boot check as one root file, never a .venusian/ directory, which the framework takes for its bootstrap path', function () {
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    expect(PharBuilder::BOOT_CHECK)->not->toContain('/')
        ->and(is_dir('phar://'.$this->root.'/out.phar/.venusian'))->toBeFalse();
});

it('boot-checks the sketch the phar runs by resolving it, so a sketch the app cannot build fails the check', function () {
    file_put_contents($this->root.'/app/bootstrap/app.php', <<<'PHP'
    <?php
    // A stand-in for the framework whose sketch registry cannot build "stargazer".
    return new class {
        public function make(string $id): object
        {
            return match ($id) {
                'config' => new class { public function get(string $key): mixed { return null; } },
                default => new class {
                    public function bootstrap(): void {}
                    protected function registry(): object
                    {
                        return new class {
                            public function resolve(string $name): object { throw new RuntimeException("Target [App\\Parallel] is not instantiable while building {$name}."); }
                        };
                    }
                },
            };
        }
    };
    PHP);
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 'stargazer', 'sketches' => ['stargazer'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));
    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $phar = realpath($this->root.'/out.phar');
    $check = new Process([PHP_BINARY, $phar, 'phar://'.$phar.'/'.PharBuilder::BOOT_CHECK]);
    $check->run();

    expect($check->isSuccessful())->toBeFalse()
        ->and($check->getErrorOutput().$check->getOutput())->toContain('Target [App\Parallel] is not instantiable while building stargazer.');
});

it('boot-checks the sketch through the registry the kernel fills from discovery, as rocket does', function () {
    file_put_contents($this->root.'/app/bootstrap/app.php', <<<'PHP'
    <?php
    // Like the framework: the registry singleton knows no sketch until the kernel's protected registry() discovers them.
    $registry = new class {
        public array $sketches = [];
        public function resolve(string $name): object { return isset($this->sketches[$name]) ? new stdClass : throw new InvalidArgumentException("Unknown sketch [{$name}]."); }
    };
    $kernel = new class($registry) {
        public function __construct(private object $registry) {}
        public function bootstrap(): void {}
        protected function registry(): object { $this->registry->sketches['stargazer'] = true; return $this->registry; }
    };
    return new class($registry, $kernel) {
        public function __construct(private object $registry, private object $kernel) {}
        public function make(string $id): object
        {
            return match ($id) {
                'config' => new class { public function get(string $key): mixed { return null; } },
                'Voyager\Contracts\Sketches\SketchRegistry' => $this->registry,
                default => $this->kernel,
            };
        }
    };
    PHP);
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 'stargazer', 'sketches' => ['stargazer'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));
    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $phar = realpath($this->root.'/out.phar');
    $check = new Process([PHP_BINARY, $phar, 'phar://'.$phar.'/'.PharBuilder::BOOT_CHECK]);
    $check->run();

    expect($check->getErrorOutput().$check->getOutput())->toContain('"booted":true')
        ->and($check->isSuccessful())->toBeTrue();
});

it('keeps the app\'s empty composer.json objects as objects, which composer install requires', function () {
    file_put_contents($this->root.'/app/composer.json', '{"name": "venusian-tests/build-fixture", "type": "project", "require": {"php": "^8.4"}, "require-dev": {}, "config": {"allow-plugins": {}}, "autoload": {"psr-4": {"App\\\\": "app/"}}}');
    (new Process([PHP_BINARY, (new ExecutableFinder)->find('composer'), 'update', '--no-install', '--no-interaction', '--quiet'], $this->root.'/app'))->mustRun();
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    expect(json_decode(file_get_contents('phar://'.$this->root.'/out.phar/composer.json'))->{'require-dev'})->toBeInstanceOf(stdClass::class);
});

it('leaves the developer\'s DB_DATABASE out of the packaged .env when it seeds, so the app opens the database in its data directory', function () {
    fakeFramework($this->root.'/app');
    mkdir($this->root.'/app/database/migrations');
    file_put_contents($this->root.'/app/database/migrations/2026_01_01_000000_create_seeded.php', '<?php');
    $lines = [];
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app',
        'env' => ['APP_ID' => 'com.test.probe', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->root.'/app/database/database.sqlite'],
        'database' => ['driver' => 'sqlite', 'database' => $this->root.'/app/database/database.sqlite']]));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar', function (string $line) use (&$lines): void { $lines[] = $line; });

    expect(file_get_contents('phar://'.$this->root.'/out.phar/.env'))->toBe("APP_ID=com.test.probe\nDB_CONNECTION=sqlite\n")
        ->and($lines)->toContain("Leaving DB_DATABASE out of the packaged .env: the seeded database/database.sqlite is copied to the app's data directory on first run");
});

it('ships files named like a directory it leaves out', function () {
    mkdir($this->root.'/app/bin');
    file_put_contents($this->root.'/app/bin/build', '#!/bin/sh');
    file_put_contents($this->root.'/app/bin/tests', '#!/bin/sh');
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app']));

    (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar');

    $phar = new Phar($this->root.'/out.phar');
    expect(isset($phar['bin/build']))->toBeTrue()
        ->and(isset($phar['bin/tests']))->toBeTrue();
});

it('refuses an app whose default connection is not sqlite, the one database a packaged app uses for now', function () {
    $manifest = Manifest::fromJson(json_encode(['name' => 'Probe', 'id' => 'com.test.probe', 'version' => '1.0.0', 'sketch' => 's', 'sketches' => ['s'], 'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => $this->root.'/app',
        'database' => ['driver' => 'mysql', 'database' => 'venusian']]));

    expect(fn () => (new PharBuilder(PHP_BINARY, new Filesystem))->build($this->root.'/app', $manifest, $this->root.'/out.phar'))
        ->toThrow(RuntimeException::class, "A packaged app's database is sqlite for now; config/database.php's default connection is mysql. Set DB_CONNECTION=sqlite in build.json env (or the app's .env).");
    expect(file_exists($this->root.'/out.phar'))->toBeFalse();
});

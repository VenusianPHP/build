<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
use Venusian\Build\Build;
use Venusian\Build\Console\BuildCommand;
use Venusian\Build\Console\Interview;
use Venusian\Build\Extensions\PhpFinder;
use Venusian\Build\Phar\PharBuilder;
use Venusian\Build\Runtime\MicroCombiner;
use Venusian\Build\Runtime\RuntimeStore;
use Venusian\Build\Targets\CodeSigner;
use Venusian\Build\Targets\DebTarget;
use Venusian\Build\Targets\MacAppBundle;
use Venusian\Build\Targets\MacTarget;
use Venusian\Build\Tests\Fakes\FakeDocker;
use Venusian\Build\Tests\Fakes\FakeReleases;
use Venusian\Build\Tests\Fakes\FakeSources;

/*
 * venusian build, end to end over fakes: a fixture app, a fake php-bin
 * release whose micro.sfx is a shell script, a PHP described by a closure
 * whose extension_dir holds appkit.so and epoll.so, and recorded codesign
 * calls; a .deb target over a fake Packagist and a fake docker CLI. The host
 * is named, so both paths run anywhere.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-build-cmd-'.bin2hex(random_bytes(6));
    $files = new Filesystem;
    $files->mirror(__DIR__.'/../Fixtures/app', $this->root.'/app');
    // The fixture lock names packages composer would fetch; this copy resolves venusian/framework to a stub path package, so the stage installs offline.
    mkdir($this->root.'/framework-stub');
    file_put_contents($this->root.'/framework-stub/composer.json', json_encode(['name' => 'venusian/framework', 'version' => '0.10.6']));
    $manifest = ['name' => 'venusian-tests/build-fixture', 'type' => 'project', 'require' => ['php' => '^8.4', 'venusian/framework' => '*'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]];
    $manifest['repositories'] = ['stub' => ['type' => 'path', 'url' => $this->root.'/framework-stub']];
    file_put_contents($this->root.'/app/composer.json', json_encode($manifest));
    (new Process([PHP_BINARY, (new ExecutableFinder)->find('composer'), 'update', '--no-install', '--no-interaction', '--quiet'], $this->root.'/app'))->mustRun();
    mkdir($this->root.'/app/vendor', 0777, true);
    touch($this->root.'/app/vendor/autoload.php');
    file_put_contents($this->root.'/app/build.json', json_encode(['extensions' => ['appkit'], 'version' => '2.0.0']));
    mkdir($this->root.'/ext');
    file_put_contents($this->root.'/ext/appkit.so', 'APPKIT');

    file_put_contents($this->root.'/ext/epoll.so', 'EPOLL');

    $this->commands = [];
    $run = function (array $command, ?string $cwd = null): void {
        $this->commands[] = $command;
    };
    $this->docker = new FakeDocker($this->root);
    $this->deb = fn (): DebTarget => new DebTarget('x86_64', FakeSources::make($this->root.'/cache'), $this->docker->docker(), []);
    $this->make = function (string $host, array $extra = [], string $os_family = 'Darwin') use ($files, $run): Build {
        $describe = fn (string $binary): ?array => $binary === '/fake/php' ? ['zts' => false, 'version' => '8.4.25', 'extension_dir' => $this->root.'/ext'] : null;
        $mac = new MacTarget(new RuntimeStore($this->root.'/runtimes', new FakeReleases, new MicroCombiner), new MicroCombiner, new MacAppBundle($files, $run), new CodeSigner($run), fn (?string $configured): PhpFinder => new PhpFinder($configured, $describe, '/fake/php'), $os_family);

        return new Build(new PharBuilder(PHP_BINARY, $files), [$mac, ...$extra], $host);
    };
    $this->build = ($this->make)('macos-arm64');
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('refuses outside a Venusian app, naming what is missing', function () {
    mkdir($this->root.'/empty');
    $tester = new CommandTester(new BuildCommand($this->build, new Interview));

    expect($tester->execute(['--dir' => $this->root.'/empty'], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay())->toContain('composer.json does not require venusian/framework')
        ->and($tester->getDisplay())->toContain('computer is missing')
        ->and($tester->getDisplay())->toContain('bootstrap/app.php is missing');
});

it('builds a signed bundle with the runtime, the phar and the bundled extension', function () {
    $tester = new CommandTester(new BuildCommand($this->build, new Interview));

    $exit = $tester->execute(['--dir' => $this->root.'/app'], ['interactive' => false]);
    expect($exit)->toBe(0, $tester->getDisplay());

    $app = $this->root.'/app/build/Star Gazer.app';
    $binary = (string) file_get_contents($app.'/Contents/MacOS/star-gazer-bin');

    expect($tester->getDisplay())->toContain($app)
        ->and(str_starts_with($binary, "#!/bin/sh\n"))->toBeTrue()
        ->and($binary)->toContain("micro.php_binary=./star-gazer-bin\nextension_dir=lib\nextension=appkit.so\n")
        ->and(file_get_contents($app.'/Contents/MacOS/lib/appkit.so'))->toBe('APPKIT')
        ->and(file_get_contents($app.'/Contents/Info.plist'))->toContain('<string>2.0.0</string>')
        ->and($this->commands)->toBe([['codesign', '--force', '--sign', '-', $app]])
        ->and(file_get_contents($this->root.'/app/build/.gitignore'))->toBe("*\n");

    $phar = substr($binary, strpos($binary, '<?php'));
    expect($phar)->toContain("'rocket', 'stargazer'");
});

it('takes the interview defaults on Enter and a name argument over them', function () {
    Prompt::fake([Key::ENTER, Key::ENTER, 'A <a@b.c>', Key::ENTER, 'Sky', Key::ENTER, Key::ENTER]);
    $tester = new CommandTester(new BuildCommand($this->build, new Interview));

    $exit = $tester->execute(['name' => 'Night Sky', '--dir' => $this->root.'/app']);

    expect($exit)->toBe(0, $tester->getDisplay())
        ->and(is_dir($this->root.'/app/build/Night Sky.app'))->toBeTrue()
        ->and(file_get_contents($this->root.'/app/build/Night Sky.app/Contents/Info.plist'))->toContain('<string>com.venusian.app</string>');

    expect(json_decode(file_get_contents($this->root.'/app/build.json'), true))->toMatchArray(['id' => 'com.venusian.app', 'name' => 'Night Sky', 'author' => 'A <a@b.c>', 'summary' => 'Sky']);
});

it('builds a .deb on a Linux host and asks no signing question', function () {
    file_put_contents($this->root.'/app/build.json', json_encode(['extensions' => ['appkit'], 'version' => '2.0.0', 'targets' => ['linux-x86_64']]));
    Prompt::fake([Key::ENTER, Key::ENTER, 'A <a@b.c>', Key::ENTER, 'Sky', Key::ENTER]);
    $tester = new CommandTester(new BuildCommand(($this->make)('linux-x86_64', [($this->deb)()]), new Interview));

    expect($tester->execute(['--dir' => $this->root.'/app']))->toBe(0, $tester->getDisplay())
        ->and(file_get_contents($this->root.'/app/build/star-gazer_2.0.0_amd64.deb'))->toBe('DEBFILE')
        ->and($tester->getDisplay())->toContain('star-gazer_2.0.0_amd64.deb')
        ->and($tester->getDisplay())->toContain('Leaving out appkit: php-io-extensions/appkit v0.10.0 builds on darwin only')
        ->and($tester->getDisplay())->not->toContain('Signing');
});

it('builds the available targets when build.json lists both OSes, and says why the other waits', function () {
    file_put_contents($this->root.'/app/build.json', json_encode(['version' => '2.0.0', 'author' => 'A <a@b.c>', 'targets' => ['macos-arm64', 'linux-x86_64']]));
    $tester = new CommandTester(new BuildCommand(($this->make)('macos-arm64', [($this->deb)()], 'Linux'), new Interview));

    expect($tester->execute(['--dir' => $this->root.'/app'], ['interactive' => false]))->toBe(0, $tester->getDisplay())
        ->and($tester->getDisplay())->toContain('Skipping macos-arm64: macos-arm64 builds run on a Mac')
        ->and(is_file($this->root.'/app/build/star-gazer_2.0.0_amd64.deb'))->toBeTrue();
});

it('refuses a target no wired target builds', function () {
    file_put_contents($this->root.'/app/build.json', json_encode(['extensions' => ['appkit'], 'targets' => ['linux-arm64']]));
    $tester = new CommandTester(new BuildCommand($this->build, new Interview));

    expect($tester->execute(['--dir' => $this->root.'/app'], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Target linux-arm64 is not one venusian build knows: macos-arm64.');
});

it('refuses a target it does not know', function () {
    file_put_contents($this->root.'/app/build.json', json_encode(['targets' => ['windows-x86_64']]));
    $tester = new CommandTester(new BuildCommand($this->build, new Interview));

    expect($tester->execute(['--dir' => $this->root.'/app'], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay())->toContain('build.json targets: windows-x86_64 is not one of macos-arm64, linux-arm64, linux-x86_64');
});

it('refuses a machine it has no target for', function () {
    $tester = new CommandTester(new BuildCommand(($this->make)('macos-x86_64'), new Interview));

    expect($tester->execute(['--dir' => $this->root.'/app'], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Target macos-x86_64 is not one venusian build knows: macos-arm64.');
});

it('names this machine from its OS family and CPU', function () {
    expect(Build::hostName('Darwin', 'arm64'))->toBe('macos-arm64')
        ->and(Build::hostName('Linux', 'aarch64'))->toBe('linux-arm64')
        ->and(Build::hostName('Linux', 'x86_64'))->toBe('linux-x86_64')
        ->and(Build::hostName('Linux', 'AMD64'))->toBe('linux-x86_64')
        ->and(Build::hostName('Windows', 'AMD64'))->toBe('windows-x86_64');
});

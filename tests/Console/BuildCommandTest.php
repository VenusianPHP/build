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
use Venusian\Build\Targets\MacAppBundle;
use Venusian\Build\Tests\Fakes\FakeReleases;

/*
 * venusian build, end to end over fakes: a fixture app, a fake php-bin
 * release whose micro.sfx is a shell script, an NTS PHP described by a
 * closure whose extension_dir holds appkit.so, and recorded codesign calls.
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
    file_put_contents($this->root.'/app/config/build.php', "<?php\n\nreturn ['extensions' => ['appkit'], 'version' => '2.0.0'];\n");
    mkdir($this->root.'/ext');
    file_put_contents($this->root.'/ext/appkit.so', 'APPKIT');

    $this->commands = [];
    $run = function (array $command, ?string $cwd = null): void {
        $this->commands[] = $command;
    };
    $describe = fn (string $binary): ?array => $binary === '/fake/php' ? ['zts' => false, 'version' => '8.4.25', 'extension_dir' => $this->root.'/ext'] : null;

    $this->build = new Build(
        new RuntimeStore($this->root.'/runtimes', new FakeReleases, new MicroCombiner),
        new PharBuilder(PHP_BINARY, $files),
        new MicroCombiner,
        new MacAppBundle($files, $run),
        new CodeSigner($run),
        fn (?string $configured): PhpFinder => new PhpFinder($configured, $describe, '/fake/php'),
        'Darwin',
    );
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
        ->and($binary)->toContain("extension_dir=lib\nextension=appkit.so\n")
        ->and(file_get_contents($app.'/Contents/MacOS/lib/appkit.so'))->toBe('APPKIT')
        ->and(file_get_contents($app.'/Contents/Info.plist'))->toContain('<string>2.0.0</string>')
        ->and($this->commands)->toBe([['codesign', '--force', '--sign', '-', $app]])
        ->and(file_get_contents($this->root.'/app/build/.gitignore'))->toBe("*\n");

    $phar = substr($binary, strpos($binary, '<?php'));
    expect($phar)->toContain("'rocket', 'stargazer'");
});

it('takes the interview defaults on Enter and a name argument over them', function () {
    Prompt::fake([Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);
    $tester = new CommandTester(new BuildCommand($this->build, new Interview));

    $exit = $tester->execute(['name' => 'Night Sky', '--dir' => $this->root.'/app']);

    expect($exit)->toBe(0, $tester->getDisplay())
        ->and(is_dir($this->root.'/app/build/Night Sky.app'))->toBeTrue()
        ->and(file_get_contents($this->root.'/app/build/Night Sky.app/Contents/Info.plist'))->toContain('<string>com.venusian.night-sky</string>');
});

it('refuses a target the host cannot build', function () {
    file_put_contents($this->root.'/app/config/build.php', "<?php\n\nreturn ['extensions' => ['appkit'], 'targets' => ['linux-arm64']];\n");
    $tester = new CommandTester(new BuildCommand($this->build, new Interview));

    expect($tester->execute(['--dir' => $this->root.'/app'], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay())->toContain('linux-arm64');
});

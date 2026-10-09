<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Venusian\Build\App\Manifest;
use Venusian\Build\Build;
use Venusian\Build\Phar\PharBuilder;
use Venusian\Build\Targets\Target;

/*
 * Build packs the phar once and hands it to every target the manifest
 * names that this machine can build; the rest are reported with why. No
 * target at all is an error naming the known ones.
 */
final class RecordingTarget implements Target
{
    public array $built = [];

    public function __construct(private readonly string $name, private readonly bool $available, private readonly bool $signs = false) {}

    public function name(): string { return $this->name; }
    public function signs(): bool { return $this->signs; }
    public function available(): bool { return $this->available; }
    public function unavailableReason(): string { return "no host for {$this->name}"; }

    public function build(string $phar, Manifest $manifest, string $output_dir, Closure $report): string
    {
        $this->built[] = [$phar, $output_dir];
        file_put_contents("{$output_dir}/{$this->name}.out", 'built');

        return "{$output_dir}/{$this->name}.out";
    }
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-build-'.bin2hex(random_bytes(4));
    (new Filesystem)->mirror(__DIR__.'/Fixtures/app', $this->root.'/app');
    $this->lines = [];
    $this->report = function (string $line): void { $this->lines[] = $line; };
    $this->manifest = fn (array $targets): Manifest => Manifest::fromJson(json_encode(['name' => 'Star Gazer', 'id' => 'com.venusian.app', 'version' => '1.0.0', 'sketch' => 'stargazer', 'sketches' => ['stargazer'], 'extensions' => [], 'targets' => $targets, 'base_path' => $this->root.'/app']));
    // The fixture lock names packages composer would fetch; this copy requires none and locks fresh, so the real PharBuilder packs offline.
    file_put_contents($this->root.'/app/composer.json', json_encode(['name' => 'venusian-tests/build-fixture', 'type' => 'project', 'require' => ['php' => '^8.4'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    (new Process([PHP_BINARY, (new ExecutableFinder)->find('composer'), 'update', '--no-install', '--no-interaction', '--quiet'], $this->root.'/app'))->mustRun();
    $this->phars = new PharBuilder(PHP_BINARY, new Filesystem);
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('builds every available target with one phar and reports the rest', function () {
    $mac = new RecordingTarget('macos-arm64', false, true);
    $x86 = new RecordingTarget('linux-x86_64', true);
    $arm = new RecordingTarget('linux-arm64', true);
    $build = new Build($this->phars, [$mac, $x86, $arm], 'macos-arm64');

    $outputs = $build->run($this->root.'/app', ($this->manifest)(['macos-arm64', 'linux-x86_64', 'linux-arm64']), $this->report);

    expect($outputs)->toBe([$this->root.'/app/build/linux-x86_64.out', $this->root.'/app/build/linux-arm64.out'])
        ->and($x86->built[0][0])->toBe($arm->built[0][0])
        ->and($this->lines)->toContain('Skipping macos-arm64: no host for macos-arm64')
        ->and(file_get_contents($this->root.'/app/build/.gitignore'))->toBe("*\n")
        ->and(array_count_values($this->lines)['Packing the phar'])->toBe(1);
});

it('builds the host target when the manifest names none', function () {
    $mac = new RecordingTarget('macos-arm64', true, true);
    $build = new Build($this->phars, [$mac, new RecordingTarget('linux-arm64', true)], 'macos-arm64');

    expect($build->run($this->root.'/app', ($this->manifest)([]), $this->report))->toBe([$this->root.'/app/build/macos-arm64.out'])
        ->and($build->signs(($this->manifest)([])))->toBeTrue()
        ->and($build->signs(($this->manifest)(['linux-arm64'])))->toBeFalse();
});

it('throws when no named target can be built here, listing why', function () {
    $build = new Build($this->phars, [new RecordingTarget('linux-arm64', false)], 'macos-arm64');

    $build->run($this->root.'/app', ($this->manifest)(['linux-arm64']), $this->report);
})->throws(RuntimeException::class, 'No target could be built on this machine: linux-arm64 (no host for linux-arm64).');

it('throws on a target name it does not know', function () {
    (new Build($this->phars, [new RecordingTarget('linux-arm64', true)], 'macos-arm64'))->targets(($this->manifest)(['freebsd-arm64']));
})->throws(RuntimeException::class, 'Target freebsd-arm64 is not one venusian build knows: linux-arm64.');

it('names this machine from its OS family and CPU', function () {
    expect(Build::hostName('Darwin', 'arm64'))->toBe('macos-arm64')
        ->and(Build::hostName('Linux', 'aarch64'))->toBe('linux-arm64')
        ->and(Build::hostName('Linux', 'x86_64'))->toBe('linux-x86_64');
});

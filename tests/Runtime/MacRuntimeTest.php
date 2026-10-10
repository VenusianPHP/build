<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Runtime\MacLibraries;
use Venusian\Build\Runtime\MacRuntime;
use Venusian\Build\Tests\Fakes\FakeMac;
use Venusian\Build\Tests\Fakes\FakeSources;

/*
 * The macOS runtime: the set resolved for darwin with the SDK's path, the
 * library prefix ensured, recipe.sh run once per set, the binary kept per
 * app and set hash, a new set replacing the old binary.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-macrt-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->mac = new FakeMac;
    $this->runtime = fn (string $libs = __DIR__.'/../../build-env/macos/libs.sh'): MacRuntime => new MacRuntime(
        FakeSources::make($this->root.'/cache'),
        new MacLibraries($this->root.'/macos', $this->mac->run(), $libs),
        $this->mac->run(),
        $this->root.'/macos/runtimes',
    );
    $this->manifest = fn (array $extensions): Manifest => Manifest::fromJson(json_encode([
        'name' => 'Star Gazer', 'id' => 'com.venusian.star-gazer', 'version' => '1.2.3', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
        'icon' => null, 'extensions' => $extensions, 'targets' => ['macos-arm64'], 'base_path' => $this->root,
    ]));
    $this->lines = [];
    $this->report = function (string $line): void {
        $this->lines[] = $line;
    };
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('compiles once per set and reuses the binary', function () {
    $first = ($this->runtime)()->compile(($this->manifest)(['appkit', 'iconv']), $this->report);
    $second = ($this->runtime)()->compile(($this->manifest)(['appkit', 'iconv']), $this->report);
    $recipes = array_filter($this->mac->commands, fn (array $c): bool => $c[0] === 'sh' && basename($c[1]) === 'recipe.sh');

    expect($first)->toBe($second)
        ->and(file_get_contents($first['binary']))->toBe('BINARY')
        ->and($first['binary'])->toStartWith($this->root.'/macos/runtimes/star-gazer/')
        ->and(is_executable($first['binary']))->toBeTrue()
        ->and($recipes)->toHaveCount(1)
        ->and($this->mac->configure)->toContain("--enable-appkit\n", "--with-iconv=/SDK/usr\n", "--enable-kqueue\n", "--enable-pcurl\n")
        ->and($first['prefix'])->toStartWith($this->root.'/macos/prefix-14.0-')
        ->and($this->lines)->toContain('Compiling PHP 8.4.26 NTS with the Venusian SAPI v0.10.2 and ctype, filter, mbstring, openssl, pdo, kqueue, pcurl, rasterize, appkit, iconv, curl')
        ->and(end($this->lines))->toStartWith('Reusing the runtime compiled for set ');
});

it('points ldap at the static OpenLDAP in the prefix', function () {
    $runtime = ($this->runtime)();
    $result = $runtime->compile(($this->manifest)(['ldap']), $this->report);

    expect($this->mac->configure)->toContain("--with-ldap={$result['prefix']}\n");
});

it('replaces the binary when the set changes', function () {
    $old = ($this->runtime)()->compile(($this->manifest)(['appkit']), $this->report);
    $new = ($this->runtime)()->compile(($this->manifest)(['appkit', 'sockets']), $this->report);

    expect($new['binary'])->not->toBe($old['binary'])
        ->and(is_file($old['binary']))->toBeFalse()
        ->and(is_file($new['binary']))->toBeTrue();
});

it('recompiles against a new library set', function () {
    $libs = $this->root.'/libs.sh';
    file_put_contents($libs, "echo one\n");
    $old = ($this->runtime)($libs)->compile(($this->manifest)(['appkit']), $this->report);
    file_put_contents($libs, "echo two\n");
    $new = ($this->runtime)($libs)->compile(($this->manifest)(['appkit']), $this->report);

    expect($new['prefix'])->not->toBe($old['prefix'])
        ->and($new['binary'])->not->toBe($old['binary']);
});

it('hands recipe.sh each extension with its build path', function () {
    $stage = null;
    $mac = $this->mac;
    $run = function (array $command, ?string $cwd = null) use ($mac, &$stage): string {
        if ($command[0] === 'sh' && basename($command[1]) === 'recipe.sh') {
            $stage = file_get_contents($command[2].'/in/extensions.list');
        }

        return ($mac->run())($command, $cwd);
    };
    $runtime = new MacRuntime(FakeSources::make($this->root.'/cache'), new MacLibraries($this->root.'/macos', $run), $run, $this->root.'/macos/runtimes');

    $runtime->compile(($this->manifest)(['appkit']), $this->report);

    expect($stage)->toBe("kqueue\t.\npcurl\text\nrasterize\t.\nappkit\t.\n");
});

it('recompiles against a new SDK', function () {
    $old = ($this->runtime)()->compile(($this->manifest)(['appkit']), $this->report);
    $this->mac->sdk_version = '26.0';
    $new = ($this->runtime)()->compile(($this->manifest)(['appkit']), $this->report);

    expect($new['binary'])->not->toBe($old['binary']);
});

it('never reuses a binary it did not finish copying into the cache', function () {
    $interrupted = new class extends Filesystem
    {
        public bool $fail = true;

        public function copy(string $originFile, string $targetFile, bool $overwriteNewerFiles = false): void
        {
            if ($this->fail && str_ends_with(dirname($originFile), '/out')) {
                @mkdir(dirname($targetFile), 0777, true);
                file_put_contents($targetFile, 'BIN');

                throw new RuntimeException('interrupted');
            }
            parent::copy($originFile, $targetFile, $overwriteNewerFiles);
        }
    };
    $runtime = fn (): MacRuntime => new MacRuntime(FakeSources::make($this->root.'/cache'), new MacLibraries($this->root.'/macos', $this->mac->run()), $this->mac->run(), $this->root.'/macos/runtimes', files: $interrupted);

    expect(fn () => $runtime()->compile(($this->manifest)(['appkit']), $this->report))->toThrow(RuntimeException::class, 'interrupted');
    $interrupted->fail = false;
    $result = $runtime()->compile(($this->manifest)(['appkit']), $this->report);

    expect(file_get_contents($result['binary']))->toBe('BINARY')
        ->and(glob(dirname($result['binary']).'/*'))->toBe([$result['binary']]);
});

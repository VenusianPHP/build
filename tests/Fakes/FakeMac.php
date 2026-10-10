<?php

namespace Venusian\Build\Tests\Fakes;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The Mac's commands, recorded. xcrun answers an SDK path and version; security
 * answers $identities and $certificates; libs.sh writes a
 * complete prefix (with the Vulkan pair); recipe.sh keeps the configure args
 * it was given and writes out/venusian; otool lists libSystem (and
 * @rpath/libvulkan.1.dylib when $vulkan); hdiutil create and convert write
 * the image they name, and detach empties the mount point;
 * notarytool answers $notary and $notary_log. Everything else succeeds.
 */
final class FakeMac
{
    /** @var list<list<string>> */
    public array $commands = [];

    public string $configure = '';

    public string $sdk_version = '15.4';

    /** `security find-identity -v -p codesigning` */
    public string $identities = "  1) 1111111111111111111111111111111111111111 \"Developer ID Application: Me (TEAM)\"\n     1 valid identities found\n";

    /** `security find-certificate -a -c <name> -Z -p` */
    public string $certificates = '';

    public function __construct(
        public bool $vulkan = false,
        public string $notary = '{"id":"n-1","status":"Accepted"}',
        public string $notary_log = '{"issues":[]}',
    ) {}

    /** @return Closure(list<string>, ?string): string */
    public function run(): Closure
    {
        $exec = $this->exec();

        return function (array $command, ?string $cwd = null) use ($exec): string {
            [$code, $out, $err] = $exec($command, $cwd);
            if ($code !== 0) {
                throw new RuntimeException(implode(' ', $command)." failed:\n{$err}");
            }

            return $out;
        };
    }

    /** @return Closure(list<string>, ?string): array{0: int, 1: string, 2: string} */
    public function exec(): Closure
    {
        return function (array $command, ?string $cwd = null): array {
            $this->commands[] = $command;
            $script = basename($command[1] ?? '');

            return match (true) {
                $command[0] === 'xcrun' && $command[1] === '--show-sdk-path' => [0, "/SDK\n", ''],
                $command[0] === 'xcrun' && $command[1] === '--show-sdk-version' => [0, $this->sdk_version."\n", ''],
                $command[0] === 'security' && $command[1] === 'find-identity' => [0, $this->identities, ''],
                $command[0] === 'security' && $command[1] === 'find-certificate' => [0, $this->certificates, ''],
                $command[0] === 'sh' && $script === 'libs.sh' => $this->libs($command[2]),
                $command[0] === 'sh' && $script === 'recipe.sh' => $this->recipe($command[2]),
                $command[0] === 'otool' => [0, $command[2].":\n\t/usr/lib/libSystem.B.dylib (compatibility version 1.0.0, current version 1351.0.0)\n"
                    .($this->vulkan ? "\t@rpath/libvulkan.1.dylib (compatibility version 1.0.0, current version 1.4.309)\n" : ''), ''],
                $command[0] === 'hdiutil' && in_array($command[1], ['create', 'convert'], true) => $this->dmg((string) end($command)),
                $command[0] === 'hdiutil' && $command[1] === 'detach' => $this->unmount((string) end($command)),
                $command[0] === 'xcrun' && $command[1] === 'notarytool' && $command[2] === 'submit' => [0, $this->notary, ''],
                $command[0] === 'xcrun' && $command[1] === 'notarytool' && $command[2] === 'log' => [0, $this->notary_log, ''],
                default => [0, '', ''],
            };
        };
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function libs(string $prefix): array
    {
        @mkdir($prefix.'/lib', 0777, true);
        @mkdir($prefix.'/share/vulkan/icd.d', 0777, true);
        file_put_contents($prefix.'/lib/libvulkan.1.dylib', 'VULKAN');
        file_put_contents($prefix.'/lib/libMoltenVK.dylib', 'MOLTENVK');
        file_put_contents($prefix.'/share/vulkan/icd.d/MoltenVK_icd.json', '{"file_format_version": "1.0.0", "ICD": {"library_path": "../../../lib/libMoltenVK.dylib", "api_version": "1.4.0", "is_portability_driver": true}}');
        touch($prefix.'/.complete');

        return [0, '', ''];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function recipe(string $stage): array
    {
        $this->configure = (string) file_get_contents($stage.'/in/configure.args');
        @mkdir($stage.'/out', 0777, true);
        file_put_contents($stage.'/out/venusian', 'BINARY');

        return [0, "Venusian SAPI 0.10.2, PHP 8.4.26 NTS\n", ''];
    }

    /** As a real detach leaves it: the mount point empty. @return array{0: int, 1: string, 2: string} */
    private function unmount(string $mount): array
    {
        foreach (array_diff(is_dir($mount) ? scandir($mount) : [], ['.', '..']) as $entry) {
            (new Filesystem)->remove("{$mount}/{$entry}");
        }

        return [0, '', ''];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function dmg(string $path): array
    {
        file_put_contents($path, 'DMG');

        return [0, '', ''];
    }
}

<?php

namespace Venusian\Build\Runtime;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Caches php-bin micro runtimes under
 * <root>/<owner>/<repo>/<tag>/<os>/<arch>/<php minor>/micro.sfx and learns
 * each one's built-in extensions by running it once.
 */
final class RuntimeStore
{
    public function __construct(
        private readonly string $root,
        private readonly GitHubReleases $releases,
        private readonly MicroCombiner $combiner,
    ) {}

    /**
     * @param  string  $os  mac, linux or win, as php-bin lays its zip out
     * @param  string  $arch  arm64 or x64
     * @param  string  $php_minor  such as 8.4
     */
    public function resolve(string $repository, string $os, string $arch, string $php_minor): Runtime
    {
        $latest = $this->releases->latest($repository);
        $dir = "{$this->root}/{$repository}/{$latest['tag']}/{$os}/{$arch}/{$php_minor}";
        $sfx = "{$dir}/micro.sfx";

        if (! is_file($sfx)) {
            $this->fetch($latest['asset'], "bin/{$os}/{$arch}/php-{$php_minor}.zip", $dir);
        }

        if (! is_file("{$dir}/extensions.json")) {
            file_put_contents("{$dir}/extensions.json", json_encode($this->probe($sfx, $dir), JSON_THROW_ON_ERROR));
        }

        $extensions = json_decode((string) file_get_contents("{$dir}/extensions.json"), true, flags: JSON_THROW_ON_ERROR);

        return new Runtime($sfx, $latest['tag'], $extensions);
    }

    private function fetch(string $asset_url, string $inner_path, string $dir): void
    {
        $files = new Filesystem;
        $files->mkdir($dir);
        $outer = "{$dir}/release.zip";
        $this->releases->download($asset_url, $outer);

        try {
            $inner = $this->member($outer, $inner_path) ?? throw new RuntimeException("The release has no {$inner_path}; no runtime for this target");
            file_put_contents("{$dir}/php.zip", $inner);
            $sfx = $this->member("{$dir}/php.zip", 'micro.sfx') ?? throw new RuntimeException("{$inner_path} holds no micro.sfx");
            file_put_contents("{$dir}/micro.sfx", $sfx);
            chmod("{$dir}/micro.sfx", 0755);
        } finally {
            $files->remove([$outer, "{$dir}/php.zip"]);
        }
    }

    /** The member named $name, or the one whose name ends in /$name (a zipball's top-level directory). */
    private function member(string $zip_path, string $name): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($zip_path) !== true) {
            throw new RuntimeException("Cannot open {$zip_path}");
        }

        $content = $zip->getFromName($name);

        for ($i = 0; $content === false && $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (str_ends_with($entry, '/'.$name)) {
                $content = $zip->getFromIndex($i);
            }
        }

        $zip->close();

        return $content === false ? null : $content;
    }

    /** @return list<string> */
    private function probe(string $sfx, string $dir): array
    {
        file_put_contents("{$dir}/probe.php", '<?php echo json_encode(get_loaded_extensions());');
        $this->combiner->combine($sfx, new MicroIni('.', [], []), "{$dir}/probe.php", "{$dir}/probe");

        try {
            $process = new Process(["{$dir}/probe"], $dir);
            $process->mustRun();
        } finally {
            unlink("{$dir}/probe.php");
            unlink("{$dir}/probe");
        }

        return array_values(json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR));
    }
}

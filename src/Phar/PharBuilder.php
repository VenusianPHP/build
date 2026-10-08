<?php

namespace Venusian\Build\Phar;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Venusian\Build\App\Manifest;

/**
 * Packs an app and its production vendor into a phar whose stub runs rocket
 * with the chosen sketch. The phar is written by a child PHP with
 * phar.readonly off; this process never needs that setting.
 */
final class PharBuilder
{
    /** @var list<string> directories that never ship; vendor is rebuilt without dev packages */
    private const EXCLUDED = ['vendor', 'storage', 'tests', 'build', '.git', '.idea', '.vscode', '.zed', 'bootstrap/cache', 'node_modules'];

    /** @var list<string> writable directories every packaged app starts with; seeded into the data directory on first run */
    private const WRITABLE = ['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/logs', 'database'];

    public function __construct(
        private readonly string $php_binary,
        private readonly Filesystem $files,
    ) {}

    public function build(string $app_dir, Manifest $manifest, string $output): void
    {
        if (! is_string($manifest->sketch) || $manifest->sketch === '') {
            throw new RuntimeException('No sketch to run: set build.sketch or choose one in the interview.');
        }

        $stage = sys_get_temp_dir().'/venusian-build-'.bin2hex(random_bytes(6));
        $this->files->mkdir($stage);

        try {
            $this->copyApp(rtrim($app_dir, '/'), $stage);
            $this->installProductionVendor($stage);
            $this->writeWritableDirectories($stage);
            $this->writeEnv($stage, $manifest);
            $this->files->mkdir(dirname($output));
            $this->files->remove($output);

            $process = new Process([$this->php_binary, '-d', 'phar.readonly=0', __DIR__.'/pack.php', $stage, $output, json_encode([
                'name' => $manifest->name,
                'bundle_id' => $manifest->bundle_id,
                'version' => $manifest->version,
                'sketch' => $manifest->sketch,
            ], JSON_THROW_ON_ERROR)]);
            $process->setTimeout(600)->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Packing the phar failed: '.$process->getErrorOutput().$process->getOutput());
            }
        } finally {
            $this->files->remove($stage);
        }
    }

    /**
     * composer run by the PHP that runs venusian, so the platform it checks (extensions included)
     * is the developer's own, not whichever PHP the composer script's shebang names.
     *
     * @return list<string>
     */
    private function composer(): array
    {
        $composer = (new ExecutableFinder)->find('composer') ?? throw new RuntimeException('composer is not on PATH');

        return [$this->php_binary, $composer];
    }

    private function copyApp(string $app_dir, string $stage): void
    {
        $finder = Finder::create()->in($app_dir)->ignoreDotFiles(false)->ignoreVCS(true)
            ->notName(['.env', '.env.*', '*.log', '.DS_Store'])
            ->exclude(self::EXCLUDED);

        foreach ($finder as $item) {
            $target = $stage.'/'.$item->getRelativePathname();

            if ($item->isDir()) {
                $this->files->mkdir($target);
            } else {
                $this->files->copy($item->getPathname(), $target, true);
            }
        }
    }

    private function installProductionVendor(string $stage): void
    {
        $process = new Process(
            // Platform requirements are the runtime's business: micro plus the bundled .so files, not the PHP running composer.
            [...$this->composer(), 'install', '--no-dev', '--no-interaction', '--no-scripts', '--no-plugins', '--optimize-autoloader', '--no-progress', '--no-ansi'],
            $stage,
            ['COMPOSER_NO_INTERACTION' => '1'],
        );
        $process->setTimeout(600)->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("composer install --no-dev failed in the build stage:\n".$process->getErrorOutput().$process->getOutput());
        }
    }

    /** The packaged app's .env, written from the manifest: the app's own .env file is never copied as it is. */
    private function writeEnv(string $stage, Manifest $manifest): void
    {
        if ($manifest->env === []) {
            return;
        }

        $lines = [];

        foreach ($manifest->env as $key => $value) {
            $lines[] = $key.'='.(preg_match('/^[A-Za-z0-9_.\/:-]*$/', $value) ? $value : '"'.addcslashes($value, '"\\').'"');
        }

        file_put_contents($stage.'/.env', implode("\n", $lines)."\n");
    }

    /** storage/ ships as a skeleton; database/ ships as it is, with an ignore file only when it had none. */
    private function writeWritableDirectories(string $stage): void
    {
        foreach (self::WRITABLE as $directory) {
            $this->files->mkdir($stage.'/'.$directory);

            if (! is_file($stage.'/'.$directory.'/.gitignore')) {
                file_put_contents($stage.'/'.$directory.'/.gitignore', "*\n!.gitignore\n");
            }
        }
    }
}

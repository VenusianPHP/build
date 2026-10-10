<?php

namespace Venusian\Build\Phar;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Venusian\Build\App\Manifest;
use Venusian\Build\Extensions\ExtensionScan;
use Venusian\Build\Extensions\SymbolMap;

/**
 * Packs an app and its production vendor into a phar whose stub runs rocket
 * with the chosen sketch. The phar is written by a child PHP with
 * phar.readonly off; this process never needs that setting.
 */
final class PharBuilder
{
    /**
     * The boot check's path inside every phar; MacTarget and DebTarget run it with the built binary.
     * One root file: a .venusian/ directory is the framework's bootstrap path when present.
     */
    public const BOOT_CHECK = '.venusian-boot-check.php';

    /** @var list<string> directories that never ship; vendor is rebuilt without dev packages */
    private const EXCLUDED = ['vendor', 'storage', 'tests', 'build', '.git', '.idea', '.vscode', '.zed', 'bootstrap/cache', 'node_modules'];

    /** @var list<string> writable directories every packaged app starts with; seeded into the data directory on first run */
    private const WRITABLE = ['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/logs', 'database'];

    private readonly ExtensionScan $scan;

    public function __construct(
        private readonly string $php_binary,
        private readonly Filesystem $files,
        ?ExtensionScan $scan = null,
    ) {
        $this->scan = $scan ?? new ExtensionScan(SymbolMap::shipped());
    }

    /** @return array{added: array<string, string>, uses: array<string, list<string>>} the scan: extension => why, from the app's own code and database; extension => packages, from vendor */
    public function build(string $app_dir, Manifest $manifest, string $output, ?Closure $report = null): array
    {
        $report ??= static function (string $line): void {};
        if (! is_string($manifest->sketch) || $manifest->sketch === '') {
            throw new RuntimeException('No sketch to run: set build.sketch or choose one in the interview.');
        }
        $driver = $manifest->database['driver'] ?? null;
        if ($driver !== null && $driver !== 'sqlite') {
            throw new RuntimeException("A packaged app's database is sqlite for now; config/database.php's default connection is {$driver}. Set DB_CONNECTION=sqlite in build.json env (or the app's .env).");
        }

        $stage = sys_get_temp_dir().'/venusian-build-'.bin2hex(random_bytes(6));
        $this->files->mkdir($stage);

        try {
            $this->copyApp(rtrim($app_dir, '/'), $stage);
            $this->absolutePathRepositories(rtrim($app_dir, '/'), $stage);
            $this->installProductionVendor($stage);
            $this->publishPathPackages(rtrim($app_dir, '/'), $stage);
            $this->dumpAutoloader($stage);
            $this->writeWritableDirectories($stage);
            $seeds = $this->seedsDatabase($app_dir, $manifest, $report);
            $this->writeEnv($stage, $manifest, $seeds, $report);
            if ($seeds) {
                $this->seedDatabase($stage, $report);
            }
            $this->files->copy(__DIR__.'/boot-check.php', "{$stage}/".self::BOOT_CHECK, true);
            $found = $this->scan->scan($stage);
            if ($driver === 'sqlite') {
                $found['added']['pdo_sqlite'] ??= "config/database.php's default connection is sqlite";
            }
            $this->files->mkdir(dirname($output));
            $this->files->remove($output);

            $process = new Process([$this->php_binary, '-d', 'phar.readonly=0', __DIR__.'/pack.php', $stage, $output, json_encode([
                'name' => $manifest->name,
                'id' => $manifest->id,
                'version' => $manifest->version,
                'sketch' => $manifest->sketch,
            ], JSON_THROW_ON_ERROR)]);
            $process->setTimeout(600)->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Packing the phar failed: '.$process->getErrorOutput().$process->getOutput());
            }

            return $found;
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
        // Directories only: a file named like one (bin/build) ships.
        $excluded = '#(^|/)(?:'.implode('|', array_map(fn (string $dir): string => preg_quote($dir, '#'), self::EXCLUDED)).')/#';

        foreach (PublishedFiles::in($app_dir, self::EXCLUDED) as $path) {
            $name = basename($path);
            if (preg_match($excluded, $path) || $name === '.env' || str_starts_with($name, '.env.') || str_ends_with($name, '.log') || $name === '.DS_Store') {
                continue;
            }
            $this->files->copy("{$app_dir}/{$path}", "{$stage}/{$path}", true);
        }
    }

    /**
     * composer install runs in the stage, where a relative path repository points nowhere: the stage's manifest and lock get absolute ones.
     * Decoded as objects, so `{}` stays an object (composer refuses `[]` where its schema wants one); written only when a URL changed.
     */
    private function absolutePathRepositories(string $app_dir, string $stage): void
    {
        $absolute = fn (string $url): string => str_starts_with($url, '/') ? $url : (string) (realpath("{$app_dir}/{$url}") ?: "{$app_dir}/{$url}");
        $rewrite = function (string $file, Closure $urls) use ($absolute): void {
            if (! is_file($file)) {
                return;
            }
            $json = json_decode((string) file_get_contents($file), false, flags: JSON_THROW_ON_ERROR);
            $changed = false;
            foreach ($urls($json) as $holder) {
                $url = $absolute((string) $holder->url);
                $changed = $changed || $url !== $holder->url;
                $holder->url = $url;
            }
            if ($changed) {
                file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
        };

        $rewrite("{$stage}/composer.json", fn (object $json): array => array_values(array_filter((array) ($json->repositories ?? []), fn (mixed $repository): bool => is_object($repository) && ($repository->type ?? null) === 'path' && isset($repository->url))));
        $rewrite("{$stage}/composer.lock", fn (object $lock): array => array_values(array_filter(array_map(fn (object $package): ?object => $package->dist ?? null, (array) ($lock->packages ?? [])), fn (?object $dist): bool => ($dist->type ?? null) === 'path' && isset($dist->url))));
    }

    /**
     * A path-repository package arrives as a link into its developer's tree (or a copy of all of it):
     * it becomes a copy of what that tree publishes. Filesystem::remove unlinks a link and never
     * recurses into its target, so the developer's tree is untouched.
     */
    private function publishPathPackages(string $app_dir, string $stage): void
    {
        $installed = json_decode((string) file_get_contents("{$stage}/vendor/composer/installed.json"), true, flags: JSON_THROW_ON_ERROR);

        foreach ($installed['packages'] ?? [] as $package) {
            if (($package['dist']['type'] ?? null) !== 'path') {
                continue;
            }
            $url = (string) $package['dist']['url'];
            $source = realpath(str_starts_with($url, '/') ? $url : "{$app_dir}/{$url}");
            if ($source === false) {
                throw new RuntimeException("The path repository of {$package['name']} is gone: {$url}");
            }

            $target = "{$stage}/vendor/{$package['name']}";
            $this->files->remove($target);
            foreach (PublishedFiles::in($source, ['vendor']) as $path) {
                $this->files->copy("{$source}/{$path}", "{$target}/{$path}", true);
            }
        }
    }

    private function installProductionVendor(string $stage): void
    {
        $process = new Process(
            // Platform requirements are the runtime's business: the compiled-in extensions, not the PHP running composer.
            [...$this->composer(), 'install', '--no-dev', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress', '--no-ansi'],
            $stage,
            ['COMPOSER_NO_INTERACTION' => '1'],
        );
        $process->setTimeout(600)->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("composer install --no-dev failed in the build stage:\n".$process->getErrorOutput().$process->getOutput());
        }
    }

    /** After the path packages are copies, so their classes are in the map; nothing falls back to PSR-4 lookups at run time. */
    private function dumpAutoloader(string $stage): void
    {
        $process = new Process(
            [...$this->composer(), 'dump-autoload', '--no-dev', '--classmap-authoritative', '--no-scripts', '--no-plugins', '--no-interaction', '--no-ansi'],
            $stage,
            ['COMPOSER_NO_INTERACTION' => '1'],
        );
        $process->setTimeout(600)->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("composer dump-autoload --classmap-authoritative failed in the build stage:\n".$process->getErrorOutput().$process->getOutput());
        }
    }

    /**
     * The packaged app's .env, written from the manifest: the app's own .env file is never copied as it is.
     * With a seeded database, the developer's DB_DATABASE (a path on their machine) stays out: the framework
     * then opens database/database.sqlite in the app's data directory, where the seed is copied.
     */
    private function writeEnv(string $stage, Manifest $manifest, bool $seeds, Closure $report): void
    {
        $env = $manifest->env;
        if ($seeds && array_key_exists('DB_DATABASE', $env)) {
            unset($env['DB_DATABASE']);
            $report("Leaving DB_DATABASE out of the packaged .env: the seeded database/database.sqlite is copied to the app's data directory on first run");
        }

        if ($env === []) {
            return;
        }

        $lines = [];

        foreach ($env as $key => $value) {
            $lines[] = $key.'='.(preg_match('/^[A-Za-z0-9_.\/:-]*$/', $value) ? $value : '"'.addcslashes($value, '"\\').'"');
        }

        file_put_contents($stage.'/.env', implode("\n", $lines)."\n");
    }

    /** A sqlite app whose file is its own database/database.sqlite ships a fresh one; any other file is said and left alone. */
    private function seedsDatabase(string $app_dir, Manifest $manifest, Closure $report): bool
    {
        if (($manifest->database['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $real = static fn (string $path): string => (realpath(dirname($path)) ?: dirname($path)).'/'.basename($path);
        $file = $manifest->database['database'];
        if ($real($file) !== $real(rtrim($app_dir, '/').'/database/database.sqlite')) {
            $report("Not seeding a database: config/database.php's sqlite file is {$file}, not the app's database/database.sqlite");

            return false;
        }

        return true;
    }

    /** database/database.sqlite in the stage, fresh and migrated from database/migrations, never the developer's own file. */
    private function seedDatabase(string $stage, Closure $report): void
    {
        $this->files->remove(glob("{$stage}/database/*.sqlite*") ?: []);
        $this->files->dumpFile("{$stage}/database/database.sqlite", '');
        $home = sys_get_temp_dir().'/venusian-seed-'.bin2hex(random_bytes(6));
        // The framework will not boot without a writable bootstrap/cache; what it caches there names stage paths, so none of it ships.
        $this->files->mkdir([$home, "{$stage}/bootstrap/cache"]);

        try {
            $process = new Process([$this->php_binary, __DIR__.'/seed.php', $stage], $stage, [
                'HOME' => $home, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => "{$stage}/database/database.sqlite",
            ]);
            $process->setTimeout(600)->run();
        } finally {
            $this->files->remove([$home, "{$stage}/bootstrap/cache"]);
        }

        // The framework renders a boot failure and still exits 0: only the count seed.php prints last says the migrations ran.
        $last = trim((string) strrchr("\n".trim($process->getOutput()), "\n"));
        if (! $process->isSuccessful() || ! ctype_digit($last)) {
            throw new RuntimeException("Migrating the packaged database failed:\n".trim($process->getErrorOutput()."\n".$process->getOutput()));
        }

        $count = (int) $last;
        $report("Seeding database/database.sqlite from {$count} migration".($count === 1 ? '' : 's'));
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

<?php

namespace Venusian\Build\App;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reads the manifest from the app's own files, without booting the framework.
 *
 * build.json gives the build facts over their defaults. config/app.php is a
 * plain `return [...]` file that calls env(); a child PHP evaluates it with
 * env() answering from .env and the environment, so app.name and app.id come
 * out as the app wrote them. app.id is the app's identity: build.json may
 * repeat it and must then match.
 */
final class ManifestReader
{
    public function __construct(
        private readonly string $app_dir,
        private readonly string $php_binary = PHP_BINARY,
    ) {}

    public function read(): Manifest
    {
        $build = (new BuildJson($this->app_dir))->read();
        $config = $this->evaluate($build);
        $name = (string) ($build['name'] ?? $config['app']['name'] ?? 'Venusian');
        $sketches = $config['sketches'];

        $app_id = $config['app']['id'] ?? null;
        if (! is_string($app_id) || $app_id === '') {
            throw new RuntimeException("config/app.php has no app.id; add 'id' => env('APP_ID', 'com.venusian.app') under name (framework 0.10 ships it).");
        }

        $id = $build['id'] ?? $app_id;
        if ($id !== $app_id) {
            throw new RuntimeException("build.json names {$id} but config/app.php resolves app.id to {$app_id}; make them match, the toolkit engines get pissy when app ids don't match.");
        }

        $lock = $this->lock();
        $extensions = array_map('strtolower', [...$build['extensions'], ...$this->lockExtensions($lock)]);
        $extensions = array_values(array_unique($extensions));
        sort($extensions);

        $env = array_diff_key(
            array_map('strval', [...$config['dotenv'], ...(array) $build['env']]),
            array_flip((array) $build['env_except']),
        );
        $env['APP_ID'] = $id;

        return new Manifest(
            name: $name,
            id: $id,
            version: (string) $build['version'],
            sketch: $build['sketch'] ?? (count($sketches) === 1 ? $sketches[0] : null),
            sketches: $sketches,
            icon: $build['icon'],
            extensions: $extensions,
            targets: array_values((array) $build['targets']),
            base_path: $this->app_dir,
            env: $env,
            summary: (string) $build['summary'],
            description: (string) $build['description'],
            author: (string) $build['author'],
            homepage: (string) $build['homepage'],
            license: (string) $build['license'],
            category: (string) $build['category'],
            zts: (bool) $build['zts'],
            windowed: $this->windowed($lock),
            build: (int) $build['build'],
            permissions: (array) $build['permissions'],
            database: $config['database'] ?? null,
        );
    }

    /**
     * config/app.php, the sketch names and the parsed .env, from a child PHP, evaluated with build.json env over .env.
     *
     * @param  array<string, mixed>  $build
     * @return array{app: array<string, mixed>, sketches: list<string>, dotenv: array<string, string>, database: array{driver: string, database: string}|null}
     */
    private function evaluate(array $build): array
    {
        $packaged = json_encode(['env' => (object) (array) $build['env'], 'except' => array_values((array) $build['env_except'])], JSON_THROW_ON_ERROR);
        $process = new Process([$this->php_binary, __DIR__.'/read.php', $this->app_dir, $packaged]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Could not read the app's config: ".$process->getErrorOutput().$process->getOutput());
        }

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> composer.lock, or an empty lock */
    private function lock(): array
    {
        $path = $this->app_dir.'/composer.lock';

        return is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : ['packages' => []];
    }

    /**
     * @param  array<string, mixed>  $lock
     * @return list<string>
     */
    private function lockExtensions(array $lock): array
    {
        $names = [];

        foreach ($lock['packages'] ?? [] as $package) {
            foreach (array_keys($package['require'] ?? []) as $requirement) {
                if (str_starts_with($requirement, 'ext-')) {
                    $names[] = substr($requirement, 4);
                }
            }
        }

        // The app's own composer.json requirements: composer keeps them under platform, not in packages.
        foreach (array_keys($lock['platform'] ?? []) as $requirement) {
            if (str_starts_with((string) $requirement, 'ext-')) {
                $names[] = substr((string) $requirement, 4);
            }
        }

        return $names;
    }

    /** A toolkit package in the lock makes a windowed app. @param  array<string, mixed>  $lock */
    private function windowed(array $lock): bool
    {
        foreach ($lock['packages'] ?? [] as $package) {
            if (str_starts_with((string) ($package['name'] ?? ''), 'jovian/')) {
                return true;
            }
        }

        return false;
    }
}

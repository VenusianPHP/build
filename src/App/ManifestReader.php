<?php

namespace Venusian\Build\App;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reads the manifest from the app's own files, without booting the framework.
 *
 * config/build.php and config/app.php are plain `return [...]` files that
 * call env(); a child PHP evaluates them with env() answering from .env
 * and the environment, so the app's defaults come out as the app wrote them.
 */
final class ManifestReader
{
    private const DEFAULTS = [
        'name' => null,
        'bundle_id' => null,
        'version' => '0.1.0',
        'sketch' => null,
        'icon' => null,
        'extensions' => [],
        'php' => null,
        'repository' => 'phpacker/php-bin',
        'sign' => 'adhoc',
        'targets' => ['macos-arm64'],
        'env' => [],
        'env_except' => [],
    ];

    public function __construct(
        private readonly string $app_dir,
        private readonly string $php_binary = PHP_BINARY,
    ) {}

    public function read(): Manifest
    {
        $config = $this->evaluate();
        $build = array_merge(self::DEFAULTS, $config['build']);
        $name = (string) ($build['name'] ?? $config['app']['name'] ?? 'Venusian');
        $sketches = $config['sketches'];

        $extensions = array_map('strtolower', [...$build['extensions'], ...$this->lockExtensions()]);
        $extensions = array_values(array_unique($extensions));
        sort($extensions);

        return new Manifest(
            name: $name,
            bundle_id: (string) ($build['bundle_id'] ?? 'com.venusian.'.trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)), '-')),
            version: (string) $build['version'],
            sketch: $build['sketch'] ?? (count($sketches) === 1 ? $sketches[0] : null),
            sketches: $sketches,
            icon: $build['icon'],
            extensions: $extensions,
            php: $build['php'],
            repository: (string) $build['repository'],
            sign: (string) $build['sign'],
            targets: array_values((array) $build['targets']),
            base_path: $this->app_dir,
            env: array_diff_key(
                array_map('strval', [...$config['dotenv'], ...(array) $build['env']]),
                array_flip((array) $build['env_except']),
            ),
        );
    }

    /**
     * config/build.php, config/app.php, the sketch names and the parsed .env, from a child PHP.
     *
     * @return array{build: array<string, mixed>, app: array<string, mixed>, sketches: list<string>, dotenv: array<string, string>}
     */
    private function evaluate(): array
    {
        $process = new Process([$this->php_binary, __DIR__.'/read.php', $this->app_dir]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Could not read the app's config: ".$process->getErrorOutput().$process->getOutput());
        }

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function lockExtensions(): array
    {
        $lock = $this->app_dir.'/composer.lock';

        if (! is_file($lock)) {
            return [];
        }

        $names = [];
        $packages = json_decode((string) file_get_contents($lock), true, flags: JSON_THROW_ON_ERROR)['packages'] ?? [];

        foreach ($packages as $package) {
            foreach (array_keys($package['require'] ?? []) as $requirement) {
                if (str_starts_with($requirement, 'ext-')) {
                    $names[] = substr($requirement, 4);
                }
            }
        }

        return $names;
    }
}

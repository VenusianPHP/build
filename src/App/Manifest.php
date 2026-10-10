<?php

namespace Venusian\Build\App;

/** The build's view of an app: build.json over defaults, plus what the tree says. */
final readonly class Manifest
{
    /**
     * @param  list<string>  $sketches  every registered sketch name
     * @param  list<string>  $extensions  extension names the app requires
     * @param  list<string>  $targets  empty means the machine running the build
     * @param  array<string, string>  $env  the packaged app's .env: the app's .env, build.env over it, less build.env_except, APP_ID always
     * @param  bool  $windowed  the app has a toolkit (a jovian/* package), so it gets a desktop entry and icon
     * @param  int  $build  build.json build: the .app's CFBundleVersion
     * @param  array<string, string>  $permissions  build.json permissions: permission => the sentence macOS shows when it asks
     * @param  array{driver: string, database: string}|null  $database  config/database.php's default connection: its driver and database
     * @param  array<string, list<string>>  $uses  extension => packages whose code calls it; filled by the build's scan
     */
    public function __construct(
        public string $name,
        public string $id,
        public string $version,
        public ?string $sketch,
        public array $sketches,
        public ?string $icon,
        public array $extensions,
        public array $targets,
        public string $base_path,
        public array $env = [],
        public string $summary = '',
        public string $description = '',
        public string $author = '',
        public string $homepage = '',
        public string $license = '',
        public string $category = 'Utility',
        public bool $zts = false,
        public bool $windowed = false,
        public int $build = 1,
        public array $permissions = [],
        public ?array $database = null,
        public array $uses = [],
    ) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(
            name: (string) $data['name'],
            id: (string) $data['id'],
            version: (string) $data['version'],
            sketch: $data['sketch'] ?? null,
            sketches: array_values($data['sketches'] ?? []),
            icon: $data['icon'] ?? null,
            extensions: array_values($data['extensions'] ?? []),
            targets: array_values($data['targets'] ?? []),
            base_path: (string) $data['base_path'],
            env: array_map('strval', (array) ($data['env'] ?? [])),
            summary: (string) ($data['summary'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            author: (string) ($data['author'] ?? ''),
            homepage: (string) ($data['homepage'] ?? ''),
            license: (string) ($data['license'] ?? ''),
            category: (string) ($data['category'] ?? 'Utility'),
            zts: (bool) ($data['zts'] ?? false),
            windowed: (bool) ($data['windowed'] ?? false),
            build: (int) ($data['build'] ?? 1),
            permissions: (array) ($data['permissions'] ?? []),
            database: isset($data['database']) ? (array) $data['database'] : null,
            uses: (array) ($data['uses'] ?? []),
        );
    }

    /** @param  array<string, mixed>  $overrides */
    public function with(array $overrides): self
    {
        return new self(...array_merge(get_object_vars($this), $overrides));
    }

    /** The name as a file name: lower case, runs of anything but letters and digits become one dash. */
    public function kebab(): string
    {
        return trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $this->name)), '-');
    }
}

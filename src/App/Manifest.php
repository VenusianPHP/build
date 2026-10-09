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
     */
    public function __construct(
        public string $name,
        public string $id,
        public string $version,
        public ?string $sketch,
        public array $sketches,
        public ?string $icon,
        public array $extensions,
        public ?string $php,
        public string $repository,
        public string $sign,
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
            php: $data['php'] ?? null,
            repository: (string) ($data['repository'] ?? 'phpacker/php-bin'),
            sign: (string) ($data['sign'] ?? 'adhoc'),
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

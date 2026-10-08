<?php

namespace Venusian\Build\App;

/** The build's view of an app: config/build.php over defaults, plus what the tree says. */
final readonly class Manifest
{
    /**
     * @param  list<string>  $sketches  every registered sketch name
     * @param  list<string>  $extensions  extension names the app requires
     * @param  list<string>  $targets
     * @param  array<string, string>  $env  variables written as the packaged app's .env
     */
    public function __construct(
        public string $name,
        public string $bundle_id,
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
    ) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(
            name: (string) $data['name'],
            bundle_id: (string) $data['bundle_id'],
            version: (string) $data['version'],
            sketch: $data['sketch'] ?? null,
            sketches: array_values($data['sketches'] ?? []),
            icon: $data['icon'] ?? null,
            extensions: array_values($data['extensions'] ?? []),
            php: $data['php'] ?? null,
            repository: (string) ($data['repository'] ?? 'phpacker/php-bin'),
            sign: (string) ($data['sign'] ?? 'adhoc'),
            targets: array_values($data['targets'] ?? ['macos-arm64']),
            base_path: (string) $data['base_path'],
            env: array_map('strval', (array) ($data['env'] ?? [])),
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

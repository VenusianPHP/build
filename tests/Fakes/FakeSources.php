<?php

namespace Venusian\Build\Tests\Fakes;

use Venusian\Build\Sources\Sources;

/**
 * Sources over a fake Packagist: every php-io-extensions name has one v0.10.0
 * release with php-ext metadata (pcurl builds from ext/, appkit is darwin
 * only, kqueue excludes linux); downloads write "A:<url>".
 */
final class FakeSources
{
    public static function packagist(string $url): string
    {
        $name = basename($url, '.json');

        return json_encode(['packages' => ["php-io-extensions/{$name}" => [[
            'version' => 'v0.10.0',
            'dist' => ['url' => "https://x/{$name}.zip"],
            'php-ext' => [
                'build-path' => $name === 'pcurl' ? 'ext' : null,
                'configure-options' => [['name' => "enable-{$name}"]],
                'os-families' => $name === 'appkit' ? ['darwin'] : null,
                'os-families-exclude' => $name === 'kqueue' ? ['linux'] : null,
            ],
        ]]]], JSON_THROW_ON_ERROR);
    }

    public static function make(string $cache): Sources
    {
        return new Sources($cache, self::packagist(...), fn (string $url, string $path) => file_put_contents($path, "A:{$url}"));
    }
}

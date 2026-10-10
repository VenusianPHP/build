<?php

namespace Venusian\Build\Tests\Fakes;

use Venusian\Build\Sources\Sources;

/**
 * Sources over a fake Packagist: every php-io-extensions name has one v0.10.0
 * release at ref-<name> with php-ext metadata (pcurl builds from ext/, appkit
 * is darwin only, kqueue excludes linux) and no 0.10.x-dev branch; gtk, pcurl
 * and qt declare apt packages; downloads write "A:<url>".
 */
final class FakeSources
{
    public static function packagist(string $url): string
    {
        if (str_starts_with($url, 'https://www.php.net/releases/')) {
            return json_encode(['version' => '8.4.26']);
        }
        if (str_starts_with($url, 'https://packagist.org/search.json')) {
            return json_encode(['results' => []]);
        }
        // Packages named in full: phpredis builds either way, parallel only thread safe.
        $full = [
            'phpredis/phpredis' => ['6.3.0', ['extension-name' => 'redis', 'configure-options' => [['name' => 'disable-redis-json'], ['name' => 'enable-redis']]]],
            'pecl/parallel' => ['v1.2.15', ['extension-name' => 'parallel', 'support-nts' => false, 'configure-options' => [['name' => 'enable-parallel']]]],
        ];
        $package = substr($url, strlen('https://repo.packagist.org/p2/'), -strlen('.json'));
        if (isset($full[$package])) {
            return json_encode(['packages' => [$package => [['version' => $full[$package][0], 'dist' => ['url' => "https://x/{$package}.zip", 'reference' => 'ref-'.basename($package)], 'php-ext' => $full[$package][1]]]]], JSON_THROW_ON_ERROR);
        }
        $name = str_replace('~dev', '', basename($url, '.json'));
        $apt = [
            'gtk' => ['build' => ['libgtk-4-dev'], 'recommends' => ['libgtk-4-media-gstreamer']],
            'pcurl' => ['build' => ['libcurl4-openssl-dev']],
            'qt' => ['build' => ['qt6-base-dev', 'qt6-multimedia-dev'], 'depends' => ['qt6-wayland']],
        ][$name] ?? null;

        return json_encode(['packages' => ["php-io-extensions/{$name}" => [[
            'version' => 'v0.10.0',
            'dist' => ['url' => "https://x/{$name}.zip", 'reference' => "ref-{$name}"],
            'php-ext' => [
                'build-path' => $name === 'pcurl' ? 'ext' : null,
                'configure-options' => [['name' => "enable-{$name}"]],
                'os-families' => $name === 'appkit' ? ['darwin'] : null,
                'os-families-exclude' => $name === 'kqueue' ? ['linux'] : null,
            ],
            ...($apt === null ? [] : ['extra' => ['venusian' => ['system' => ['apt' => $apt]]]]),
        ]]]], JSON_THROW_ON_ERROR);
    }

    public static function make(string $cache): Sources
    {
        return new Sources($cache, self::packagist(...), fn (string $url, string $path) => file_put_contents($path, "A:{$url}"));
    }
}

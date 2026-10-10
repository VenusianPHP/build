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

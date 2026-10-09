<?php

namespace Venusian\Build\Sources;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * The source archives a Linux build compiles: php-src from php.net, the
 * Venusian SAPI tag from GitHub, each extension's latest tagged release from
 * Packagist. Cached under <cache>/{php-src,sapi,ext}; fetched once.
 */
final class Sources
{
    public const PHP = '8.4.26';

    public const SAPI = 'v0.10.1';

    private const VENDOR = 'php-io-extensions';

    /**
     * @param  Closure(string): string  $get  fetches a URL's body
     * @param  Closure(string, string): void  $download  fetches a URL to a path
     */
    public function __construct(
        private readonly string $cache,
        private readonly Closure $get,
        private readonly Closure $download,
    ) {}

    /** @return array{path: string, version: string} */
    public function phpSrc(string $version = self::PHP): array
    {
        return ['path' => $this->fetch("https://www.php.net/distributions/php-{$version}.tar.xz", "php-src/php-{$version}.tar.xz"), 'version' => $version];
    }

    /** @return array{path: string, version: string} */
    public function sapi(string $tag = self::SAPI): array
    {
        return ['path' => $this->fetch("https://github.com/VenusianPHP/sapi/archive/refs/tags/{$tag}.tar.gz", "sapi/sapi-{$tag}.tar.gz"), 'version' => $tag];
    }

    /**
     * os_families and os_families_exclude come from php-ext as declared; an empty os_families means every OS.
     *
     * @return array{path: string, version: string, build_path: string, configure: string, os_families: list<string>, os_families_exclude: list<string>}
     */
    public function extension(string $name): array
    {
        $package = self::VENDOR."/{$name}";

        try {
            $index = json_decode(($this->get)("https://repo.packagist.org/p2/{$package}.json"), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException("{$package} is not on Packagist ({$e->getMessage()}); tag and publish it, or drop {$name} from the app.");
        }

        $release = $index['packages'][$package][0] ?? throw new RuntimeException("{$package} has no tagged release on Packagist.");
        $version = (string) $release['version'];
        $meta = $release['php-ext'] ?? null;

        if (! is_array($meta)) {
            throw new RuntimeException("{$package} {$version} declares no php-ext build metadata in composer.json.");
        }

        $flag = $meta['configure-options'][0]['name'] ?? "enable-{$name}";

        return [
            'path' => $this->fetch((string) $release['dist']['url'], "ext/{$name}-{$version}.zip"),
            'version' => $version,
            'build_path' => trim((string) ($meta['build-path'] ?? ''), '/') ?: '.',
            'configure' => '--'.ltrim($flag, '-'),
            'os_families' => array_values((array) ($meta['os-families'] ?? [])),
            'os_families_exclude' => array_values((array) ($meta['os-families-exclude'] ?? [])),
        ];
    }

    private function fetch(string $url, string $relative): string
    {
        $path = "{$this->cache}/{$relative}";

        if (! is_file($path)) {
            (new Filesystem)->mkdir(dirname($path));
            ($this->download)($url, "{$path}.part");
            rename("{$path}.part", $path);
        }

        return $path;
    }

    /**
     * Copies a download stream to a file; a stream that ends before the announced length
     * (a dropped connection) leaves no file, so a cut-short archive is never cached.
     *
     * @param  resource  $in
     * @param  int|null  $length  Content-Length, when the server sent one
     */
    public static function save($in, string $path, ?int $length): void
    {
        $out = fopen($path, 'wb') ?: throw new RuntimeException("Cannot write {$path}");
        $copied = stream_copy_to_stream($in, $out);
        fclose($out);

        if ($copied === false || ($length !== null && $copied !== $length)) {
            unlink($path);

            throw new RuntimeException('Download cut short: '.(int) $copied.' of '.$length.' bytes; run the build again.');
        }
    }

    /**
     * The final response's Content-Length from the http wrapper's headers, which list every
     * response of a redirect chain in order; null when the final one has none (chunked).
     *
     * @param  list<string>  $headers
     */
    public static function contentLength(array $headers): ?int
    {
        $length = null;

        foreach ($headers as $header) {
            if (str_starts_with($header, 'HTTP/')) {
                $length = null;
            } elseif (preg_match('/^content-length:\s*(\d+)/i', $header, $m)) {
                $length = (int) $m[1];
            }
        }

        return $length;
    }

    /** The real fetchers: PHP streams with a User-Agent, as GitHubReleases does. */
    public static function real(string $cache): self
    {
        $context = fn () => stream_context_create(['http' => ['header' => "User-Agent: Venusian Build\r\n", 'follow_location' => 1]]);
        $get = fn (string $url): string => file_get_contents($url, false, $context()) ?: throw new RuntimeException("Cannot reach {$url}");
        $download = function (string $url, string $path) use ($context): void {
            $in = fopen($url, 'rb', false, $context()) ?: throw new RuntimeException("Cannot download {$url}");
            $length = self::contentLength((array) (stream_get_meta_data($in)['wrapper_data'] ?? []));

            try {
                self::save($in, $path, $length);
            } finally {
                fclose($in);
            }
        };

        return new self($cache, $get, $download);
    }
}

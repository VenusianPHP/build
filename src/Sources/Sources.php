<?php

namespace Venusian\Build\Sources;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * The source archives a build compiles: php-src from php.net, the
 * Venusian SAPI tag from GitHub, each extension's archive on the build's line
 * from Packagist. Cached under <cache>/{php-src,sapi,ext}; fetched once.
 */
final class Sources
{
    public const PHP = '8.4.26';

    public const SAPI = 'v0.10.2';

    /** The line every extension is taken from: its LINE.x-dev branch, else its newest LINE tag. */
    public const LINE = '0.10';

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
     * The extension on the build's line: the LINE.x-dev branch, where the work lands ahead of
     * the tags, else the newest LINE tag. The reference is the commit, so a moved branch is a
     * new archive and a new runtime. os_families and os_families_exclude come from php-ext as
     * declared; an empty os_families means every OS. apt_* come from extra.venusian.system.apt:
     * build packages for the image, depends and recommends for what the binary loads at run
     * time and dpkg-shlibdeps cannot see.
     *
     * @return array{path: string, version: string, reference: string, build_path: string, configure: string, os_families: list<string>, os_families_exclude: list<string>, apt_build: list<string>, apt_depends: list<string>, apt_recommends: list<string>}
     */
    public function extension(string $name): array
    {
        $package = self::VENDOR."/{$name}";
        $branch = $this->index("{$package}~dev", $package);
        $release = null;
        foreach (self::entries($branch, $package) as $entry) {
            if (($entry['version'] ?? null) === self::LINE.'.x-dev') {
                $release = $entry;
                break;
            }
        }

        if ($release === null) {
            $tags = $this->index($package, $package);
            if ($branch === null && $tags === null) {
                throw new RuntimeException("{$package} is not on Packagist; tag and publish it, or drop {$name} from the app.");
            }
            $line = array_filter(self::entries($tags, $package), fn (array $entry): bool => (bool) preg_match('/^v?'.preg_quote(self::LINE, '/').'\.\d+$/', (string) ($entry['version'] ?? '')));
            usort($line, fn (array $a, array $b): int => version_compare(ltrim((string) $b['version'], 'v'), ltrim((string) $a['version'], 'v')));
            $release = $line[0] ?? throw new RuntimeException("{$package} has no ".self::LINE.'.x-dev branch and no '.self::LINE." tag on Packagist; publish one, or drop {$name} from the app.");
        }

        $version = (string) $release['version'];
        $meta = $release['php-ext'] ?? null;

        if (! is_array($meta) || $meta === []) {
            throw new RuntimeException("{$package} {$version} declares no php-ext build metadata in composer.json.");
        }

        $reference = (string) ($release['dist']['reference'] ?? $release['source']['reference'] ?? $version);
        $flag = $meta['configure-options'][0]['name'] ?? "enable-{$name}";
        $apt = (array) ($release['extra']['venusian']['system']['apt'] ?? []);

        return [
            'path' => $this->fetch((string) $release['dist']['url'], "ext/{$name}-{$version}-".substr($reference, 0, 12).'.zip'),
            'version' => $version,
            'reference' => $reference,
            'build_path' => trim((string) ($meta['build-path'] ?? ''), '/') ?: '.',
            'configure' => '--'.ltrim($flag, '-'),
            'os_families' => array_values((array) ($meta['os-families'] ?? [])),
            'os_families_exclude' => array_values((array) ($meta['os-families-exclude'] ?? [])),
            'apt_build' => array_values((array) ($apt['build'] ?? [])),
            'apt_depends' => array_values((array) ($apt['depends'] ?? [])),
            'apt_recommends' => array_values((array) ($apt['recommends'] ?? [])),
        ];
    }

    /**
     * A p2 index, or null when Packagist has none under that name (404). Any other failure
     * stops the build: reading it as "no branch" would quietly build an older tag.
     *
     * @return array<string, mixed>|null
     */
    private function index(string $name, string $package): ?array
    {
        try {
            return json_decode(($this->get)("https://repo.packagist.org/p2/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
        } catch (NotFound) {
            return null;
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot reach Packagist for {$package}: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * A p2 index's entries, expanded. Packagist lists newest first and, under "minified":
     * "composer/2.0", gives each later entry only what changed from the one before it
     * ("__unset" removes a key).
     *
     * @param  array<string, mixed>|null  $index
     * @return list<array<string, mixed>>
     */
    private static function entries(?array $index, string $package): array
    {
        $minified = ($index['minified'] ?? null) === 'composer/2.0';
        $previous = [];
        $entries = [];

        foreach ((array) ($index['packages'][$package] ?? []) as $entry) {
            $full = $minified ? $previous : [];
            foreach ((array) $entry as $key => $value) {
                if ($value === '__unset') {
                    unset($full[$key]);
                } else {
                    $full[$key] = $value;
                }
            }
            $previous = $full;
            $entries[] = $full;
        }

        return $entries;
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

    /** The real fetchers: PHP streams with a User-Agent. */
    public static function real(string $cache): self
    {
        $context = fn () => stream_context_create(['http' => ['header' => "User-Agent: Venusian Build\r\n", 'follow_location' => 1]]);
        // A 404 is an answer (no branch index, say): NotFound, not a warning. Anything else is no answer.
        $get = function (string $url) use ($context): string {
            $body = @file_get_contents($url, false, $context());
            if ($body !== false) {
                return $body;
            }
            $status = array_values(array_filter(http_get_last_response_headers() ?? [], fn (string $h): bool => str_starts_with($h, 'HTTP/')));
            $why = $status !== [] ? end($status) : trim(preg_replace('/^.*: /', '', error_get_last()['message'] ?? 'no answer'));

            throw str_contains($why, ' 404') ? new NotFound("{$url}: {$why}") : new RuntimeException("Cannot reach {$url}: {$why}");
        };
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

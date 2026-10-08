<?php

namespace Venusian\Build\Runtime;

use RuntimeException;

/** GitHub's public releases API, no token. */
class GitHubReleases
{
    public function __construct(private readonly string $user_agent = 'Venusian Build') {}

    /**
     * The latest tag and the zip to download: an uploaded zip asset when the
     * release has one, else the release's source zipball (php-bin commits its
     * binaries, so the zipball carries bin/<os>/<arch>/php-<minor>.zip under
     * one top-level directory).
     *
     * @return array{tag: string, asset: string}
     */
    public function latest(string $repository): array
    {
        $release = json_decode($this->get("https://api.github.com/repos/{$repository}/releases/latest"), true, flags: JSON_THROW_ON_ERROR);

        foreach ($release['assets'] ?? [] as $asset) {
            if (str_ends_with((string) $asset['name'], '.zip')) {
                return ['tag' => (string) $release['tag_name'], 'asset' => (string) $asset['browser_download_url']];
            }
        }

        if (is_string($release['zipball_url'] ?? null)) {
            return ['tag' => (string) $release['tag_name'], 'asset' => $release['zipball_url']];
        }

        throw new RuntimeException("No zip asset or zipball on the latest release of {$repository}");
    }

    public function download(string $url, string $path): void
    {
        $in = fopen($url, 'rb', false, $this->context()) ?: throw new RuntimeException("Cannot download {$url}");
        $out = fopen($path, 'wb') ?: throw new RuntimeException("Cannot write {$path}");
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    private function get(string $url): string
    {
        return file_get_contents($url, false, $this->context()) ?: throw new RuntimeException("Cannot reach {$url}");
    }

    /** @return resource */
    private function context()
    {
        return stream_context_create(['http' => [
            'header' => "User-Agent: {$this->user_agent}\r\nAccept: application/vnd.github+json\r\n",
            'follow_location' => 1,
        ]]);
    }
}

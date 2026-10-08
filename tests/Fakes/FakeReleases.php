<?php

namespace Venusian\Build\Tests\Fakes;

use Venusian\Build\Runtime\GitHubReleases;
use ZipArchive;

/**
 * Serves one release whose zip holds, under a top-level directory the way a
 * GitHub zipball does, bin/mac/arm64/php-8.4.zip with a micro.sfx that is
 * really a shell script printing a fixed extension list.
 */
final class FakeReleases extends GitHubReleases
{
    public int $downloads = 0;

    public function __construct(public readonly string $tag = '0.4.0') {}

    public function latest(string $repository): array
    {
        return ['tag' => $this->tag, 'asset' => 'fake://'.$repository.'.zip'];
    }

    public function download(string $url, string $path): void
    {
        $this->downloads++;

        $inner = tempnam(sys_get_temp_dir(), 'inner');
        $zip = new ZipArchive;
        $zip->open($inner, ZipArchive::OVERWRITE);
        $zip->addFromString('micro.sfx', "#!/bin/sh\necho '[\"Core\",\"json\",\"phar\"]'\nexit 0\n");
        $zip->close();

        $outer = new ZipArchive;
        $outer->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $outer->addFile($inner, 'phpacker-php-bin-abc123/bin/mac/arm64/php-8.4.zip');
        $outer->close();
        unlink($inner);
    }
}
